<?php
declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[Route('/api/v1/customers')]
final readonly class CustomerController
{
    public function __construct(private Connection $db) {}

    #[Route('', methods: ['POST'])]
    public function create(Request $r): JsonResponse
    {
        try {
            $p=$this->principal($r,'customers:write'); $d=$this->customerInput($this->body($r));
            $key=trim((string)$r->headers->get('Idempotency-Key',''));
            if($key===''||strlen($key)>128) return $this->problem(400,'A valid Idempotency-Key is required.');
            $hash=hash('sha256',json_encode($d,JSON_THROW_ON_ERROR));
            return $this->db->transactional(function(Connection $db)use($p,$d,$key,$hash):JsonResponse{
                $t=$this->tenant($p); $this->tenantContext($db,$t);
                $old=$db->fetchAssociative('SELECT request_hash,response_body FROM customer_idempotency_keys WHERE tenant_id=:t AND idempotency_key=:k',['t'=>$t,'k'=>$key]);
                if($old!==false){
                    if(!hash_equals($old['request_hash'],$hash)) return $this->problem(409,'Idempotency-Key was used with another request.');
                    $payload=is_string($old['response_body'])?json_decode($old['response_body'],true,512,JSON_THROW_ON_ERROR):$old['response_body'];
                    return new JsonResponse($payload,200,['Cache-Control'=>'no-store']);
                }
                $id=Uuid::v7()->toRfc4122();
                $db->insert('customer_records',[
                    'tenant_id'=>$t,'customer_id'=>$id,'customer_type'=>$d['customerType'],'first_name'=>$d['firstName'],
                    'last_name'=>$d['lastName'],'legal_name'=>$d['legalName'],'email'=>$d['email'],
                    'email_normalized'=>$d['email']===null?null:mb_strtolower($d['email']),'phone'=>$d['phone'],
                    'vat_id'=>$d['vatId'],'locale'=>$d['locale'],'status'=>'active','version'=>1,
                    'created_by'=>$p->subject,'updated_by'=>$p->subject
                ]);
                $item=$this->load($db,$t,$id);
                $this->audit($db,$t,$id,$p->subject,'CustomerCreated',['customerType'=>$d['customerType']]);
                $db->insert('customer_idempotency_keys',['tenant_id'=>$t,'idempotency_key'=>$key,'request_hash'=>$hash,'response_body'=>json_encode($item,JSON_THROW_ON_ERROR)]);
                return new JsonResponse($item,201,['Cache-Control'=>'no-store','ETag'=>'"1"']);
            });
        } catch(\InvalidArgumentException|\JsonException $e){return $this->problem(400,$e->getMessage());}
        catch(\DomainException $e){return $this->problem(422,$e->getMessage());}
        catch(UniqueConstraintViolationException){return $this->problem(409,'Email already exists for this tenant.');}
        catch(AccessDeniedHttpException $e){return $this->problem(403,$e->getMessage());}
        catch(Throwable){return $this->problem(503,'Customer could not be created.');}
    }

    #[Route('',methods:['GET'])]
    public function list(Request $r):JsonResponse
    {
        try{$p=$this->principal($r,'customers:read');return $this->db->transactional(function(Connection $db)use($p,$r):JsonResponse{
            $t=$this->tenant($p);$this->tenantContext($db,$t);$limit=max(1,min(100,$r->query->getInt('limit',20)));$offset=max(0,$r->query->getInt('offset',0));
            $where=['tenant_id=:t','archived_at IS NULL'];$params=['t'=>$t,'limit'=>$limit,'offset'=>$offset];
            $status=$r->query->get('status');$type=$r->query->get('type');$q=trim((string)$r->query->get('q',''));
            if(is_string($status)&&in_array($status,['active','inactive'],true)){$where[]='status=:status';$params['status']=$status;}
            if(is_string($type)&&in_array($type,['individual','company'],true)){$where[]='customer_type=:type';$params['type']=$type;}
            if($q!==''){$where[]="(email_normalized LIKE :q OR lower(coalesce(first_name,'') || ' ' || coalesce(last_name,'')) LIKE :q OR lower(coalesce(legal_name,'')) LIKE :q)";$params['q']='%'.mb_strtolower($q).'%';}
            $sql=implode(' AND ',$where);
            $items=$db->fetchAllAssociative("SELECT customer_id,customer_type,first_name,last_name,legal_name,email,phone,vat_id,locale,status,version,created_at,updated_at FROM customer_records WHERE $sql ORDER BY created_at DESC LIMIT :limit OFFSET :offset",$params);
            $total=(int)$db->fetchOne("SELECT COUNT(*) FROM customer_records WHERE $sql",array_diff_key($params,['limit'=>true,'offset'=>true]));
            return new JsonResponse(['items'=>$items,'total'=>$total,'limit'=>$limit,'offset'=>$offset],200,['Cache-Control'=>'no-store']);
        });}catch(Throwable){return $this->problem(503,'Customers could not be listed.');}
    }

    #[Route('/{id}',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['GET'])]
    public function get(Request $r,string $id):JsonResponse
    {
        return $this->run($r,'customers:read',function(Connection $db,string $t)use($id):JsonResponse{
            $item=$this->load($db,$t,$id);return $item===null?$this->problem(404,'Customer not found.'):new JsonResponse($item,200,['Cache-Control'=>'no-store','ETag'=>'"'.$item['version'].'"']);
        });
    }

    #[Route('/{id}',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['PATCH'])]
    public function update(Request $r,string $id):JsonResponse
    {
        try{$body=$this->body($r);$allowed=['firstName'=>'first_name','lastName'=>'last_name','legalName'=>'legal_name','email'=>'email','phone'=>'phone','vatId'=>'vat_id','locale'=>'locale'];if(!$body)throw new \InvalidArgumentException('At least one field is required.');foreach($body as $k=>$v){if(!isset($allowed[$k])||($v!==null&&(!is_string($v)||trim($v)===''||mb_strlen(trim($v))>254)))throw new \InvalidArgumentException('Invalid or unsupported field: '.$k);$body[$k]=$v===null?null:trim($v);}if(isset($body['email'])&&filter_var($body['email'],FILTER_VALIDATE_EMAIL)===false)throw new \InvalidArgumentException('Invalid email.');if(isset($body['locale'])&&!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/',$body['locale']))throw new \InvalidArgumentException('Invalid locale.');if(isset($body['email']))$body['email']=mb_strtolower($body['email']);}
        catch(Throwable $e){return $this->problem(400,$e->getMessage());}
        return $this->run($r,'customers:write',function(Connection $db,string $t,AuthenticatedPrincipal $p)use($id,$body,$allowed):JsonResponse{
            $old=$this->load($db,$t,$id);if($old===null||$old['archived_at']!==null)return $this->problem(404,'Customer not found.');
            $sets=[];$params=['t'=>$t,'id'=>$id,'actor'=>$p->subject,'version'=>(int)$old['version']];
            foreach($body as $key=>$value){$sets[]=$allowed[$key].'=:'.$key;$params[$key]=$value;if($key==='email'){$sets[]='email_normalized=:normalized';$params['normalized']=$value===null?null:mb_strtolower($value);}}
            $sets[]='version=version+1';$sets[]='updated_at=CURRENT_TIMESTAMP';$sets[]='updated_by=:actor';
            $n=$db->executeStatement('UPDATE customer_records SET '.implode(',',$sets).' WHERE tenant_id=:t AND customer_id=:id AND version=:version AND archived_at IS NULL',$params);
            if($n!==1)return $this->problem(409,'Customer changed concurrently.');
            $this->audit($db,$t,$id,$p->subject,'CustomerUpdated',['fields'=>array_keys($body)]);
            $item=$this->load($db,$t,$id);return new JsonResponse($item,200,['Cache-Control'=>'no-store','ETag'=>'"'.$item['version'].'"']);
        });
    }

    #[Route('/{id}',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['DELETE'])]
    public function archive(Request $r,string $id):JsonResponse
    {
        return $this->run($r,'customers:manage',function(Connection $db,string $t,AuthenticatedPrincipal $p)use($id):JsonResponse{
            $n=$db->executeStatement("UPDATE customer_records SET status='archived',archived_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,updated_by=:actor,version=version+1 WHERE tenant_id=:t AND customer_id=:id AND archived_at IS NULL",['t'=>$t,'id'=>$id,'actor'=>$p->subject]);
            if($n!==1)return $this->problem(404,'Customer not found.');$this->audit($db,$t,$id,$p->subject,'CustomerArchived',[]);return new JsonResponse(null,204);
        });
    }

    #[Route('/{id}/addresses',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['GET'])]
    public function addresses(Request $r,string $id):JsonResponse
    {
        return $this->run($r,'customers:read',function(Connection $db,string $t)use($id):JsonResponse{
            if($this->load($db,$t,$id)===null)return $this->problem(404,'Customer not found.');
            return new JsonResponse(['items'=>$db->fetchAllAssociative('SELECT address_id,address_type,label,line1,line2,postal_code,city,region,country_code,is_default FROM customer_addresses WHERE tenant_id=:t AND customer_id=:id AND deleted_at IS NULL ORDER BY is_default DESC,created_at',['t'=>$t,'id'=>$id])],200,['Cache-Control'=>'no-store']);
        });
    }

    #[Route('/{id}/addresses',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['POST'])]
    public function addAddress(Request $r,string $id):JsonResponse
    {
        try{$d=$this->addressInput($this->body($r));}catch(Throwable $e){return $this->problem(400,$e->getMessage());}
        return $this->run($r,'customers:write',function(Connection $db,string $t,AuthenticatedPrincipal $p)use($id,$d):JsonResponse{
            $customer=$this->load($db,$t,$id);if($customer===null||$customer['status']==='archived')return $this->problem(404,'Active customer not found.');$address=Uuid::v7()->toRfc4122();
            if($d['isDefault'])$db->executeStatement('UPDATE customer_addresses SET is_default=false WHERE tenant_id=:t AND customer_id=:id AND address_type=:type AND deleted_at IS NULL',['t'=>$t,'id'=>$id,'type'=>$d['addressType']]);
            $db->insert('customer_addresses',['tenant_id'=>$t,'address_id'=>$address,'customer_id'=>$id,'address_type'=>$d['addressType'],'label'=>$d['label'],'line1'=>$d['line1'],'line2'=>$d['line2'],'postal_code'=>$d['postalCode'],'city'=>$d['city'],'region'=>$d['region'],'country_code'=>$d['countryCode'],'is_default'=>$d['isDefault']]);
            $this->audit($db,$t,$id,$p->subject,'CustomerAddressAdded',['addressId'=>$address]);
            return new JsonResponse($db->fetchAssociative('SELECT address_id,address_type,label,line1,line2,postal_code,city,region,country_code,is_default FROM customer_addresses WHERE tenant_id=:t AND address_id=:a',['t'=>$t,'a'=>$address]),201,['Cache-Control'=>'no-store']);
        });
    }

    #[Route('/{id}/addresses/{addressId}',requirements:['id'=>'[0-9a-fA-F-]{36}','addressId'=>'[0-9a-fA-F-]{36}'],methods:['PUT'])]
    public function updateAddress(Request $r,string $id,string $addressId):JsonResponse
    {
        try{$d=$this->addressInput($this->body($r));}catch(Throwable $e){return $this->problem(400,$e->getMessage());}
        return $this->run($r,'customers:write',function(Connection $db,string $t,AuthenticatedPrincipal $p)use($id,$addressId,$d):JsonResponse{
            if($this->load($db,$t,$id)===null)return $this->problem(404,'Customer not found.');
            $exists=$db->fetchOne('SELECT 1 FROM customer_addresses WHERE tenant_id=:t AND customer_id=:id AND address_id=:a AND deleted_at IS NULL',['t'=>$t,'id'=>$id,'a'=>$addressId]);
            if($exists===false)return $this->problem(404,'Address not found.');
            if($d['isDefault'])$db->executeStatement('UPDATE customer_addresses SET is_default=false WHERE tenant_id=:t AND customer_id=:id AND address_type=:type AND address_id<>:a AND deleted_at IS NULL',['t'=>$t,'id'=>$id,'type'=>$d['addressType'],'a'=>$addressId]);
            $n=$db->executeStatement('UPDATE customer_addresses SET address_type=:type,label=:label,line1=:line1,line2=:line2,postal_code=:postal,city=:city,region=:region,country_code=:country,is_default=:default,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:t AND customer_id=:id AND address_id=:a AND deleted_at IS NULL',['type'=>$d['addressType'],'label'=>$d['label'],'line1'=>$d['line1'],'line2'=>$d['line2'],'postal'=>$d['postalCode'],'city'=>$d['city'],'region'=>$d['region'],'country'=>$d['countryCode'],'default'=>$d['isDefault'],'t'=>$t,'id'=>$id,'a'=>$addressId]);
            if(!$n)return $this->problem(404,'Address not found.');$this->audit($db,$t,$id,$p->subject,'CustomerAddressUpdated',['addressId'=>$addressId]);
            return new JsonResponse($db->fetchAssociative('SELECT address_id,address_type,label,line1,line2,postal_code,city,region,country_code,is_default FROM customer_addresses WHERE tenant_id=:t AND address_id=:a',['t'=>$t,'a'=>$addressId]),200,['Cache-Control'=>'no-store']);
        });
    }

    #[Route('/{id}/addresses/{addressId}',requirements:['id'=>'[0-9a-fA-F-]{36}','addressId'=>'[0-9a-fA-F-]{36}'],methods:['DELETE'])]
    public function deleteAddress(Request $r,string $id,string $addressId):JsonResponse
    {
        return $this->run($r,'customers:write',function(Connection $db,string $t,AuthenticatedPrincipal $p)use($id,$addressId):JsonResponse{
            $n=$db->executeStatement('UPDATE customer_addresses SET deleted_at=CURRENT_TIMESTAMP,is_default=false,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:t AND customer_id=:id AND address_id=:a AND deleted_at IS NULL',['t'=>$t,'id'=>$id,'a'=>$addressId]);
            if(!$n)return $this->problem(404,'Address not found.');$this->audit($db,$t,$id,$p->subject,'CustomerAddressRemoved',['addressId'=>$addressId]);return new JsonResponse(null,204);
        });
    }

    #[Route('/{id}/consents',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['GET'])]
    public function consents(Request $r,string $id):JsonResponse
    {
        return $this->run($r,'customers:read',function(Connection $db,string $t)use($id):JsonResponse{
            if($this->load($db,$t,$id)===null)return $this->problem(404,'Customer not found.');
            return new JsonResponse(['items'=>$db->fetchAllAssociative('SELECT purpose,granted,source,recorded_at FROM customer_consents WHERE tenant_id=:t AND customer_id=:id ORDER BY purpose',['t'=>$t,'id'=>$id])],200,['Cache-Control'=>'no-store']);
        });
    }

    #[Route('/{id}/consents',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['PUT'])]
    public function setConsent(Request $r,string $id):JsonResponse
    {
        try{$b=$this->body($r);$purpose=$b['purpose']??null;if(!is_string($purpose)||!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/',$purpose)||!is_bool($b['granted']??null))throw new \InvalidArgumentException('purpose and boolean granted are required.');$source=is_string($b['source']??null)?mb_substr(trim($b['source']),0,80):'customer-portal';}catch(Throwable $e){return $this->problem(400,$e->getMessage());}
        return $this->run($r,'customers:write',function(Connection $db,string $t,AuthenticatedPrincipal $p)use($id,$purpose,$b,$source):JsonResponse{
            $customer=$this->load($db,$t,$id);if($customer===null||$customer['status']==='archived')return $this->problem(404,'Active customer not found.');
            $db->executeStatement('INSERT INTO customer_consents(tenant_id,customer_id,purpose,granted,source,recorded_at) VALUES(:t,:id,:purpose,:granted,:source,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id,customer_id,purpose) DO UPDATE SET granted=EXCLUDED.granted,source=EXCLUDED.source,recorded_at=CURRENT_TIMESTAMP',['t'=>$t,'id'=>$id,'purpose'=>$purpose,'granted'=>$b['granted'],'source'=>$source]);
            $this->audit($db,$t,$id,$p->subject,'CustomerConsentChanged',['purpose'=>$purpose,'granted'=>$b['granted']]);return new JsonResponse(['purpose'=>$purpose,'granted'=>$b['granted'],'source'=>$source],200,['Cache-Control'=>'no-store']);
        });
    }

    #[Route('/{id}/history',requirements:['id'=>'[0-9a-fA-F-]{36}'],methods:['GET'])]
    public function history(Request $r,string $id):JsonResponse
    {
        return $this->run($r,'customers:read',function(Connection $db,string $t)use($id):JsonResponse{
            if($this->load($db,$t,$id)===null)return $this->problem(404,'Customer not found.');
            $items=$db->fetchAllAssociative('SELECT event_id,actor_id,event_type,payload,occurred_at FROM customer_audit_events WHERE tenant_id=:t AND customer_id=:id ORDER BY occurred_at DESC LIMIT 100',['t'=>$t,'id'=>$id]);
            foreach($items as &$item)if(is_string($item['payload']))$item['payload']=json_decode($item['payload'],true,512,JSON_THROW_ON_ERROR);unset($item);
            return new JsonResponse(['items'=>$items],200,['Cache-Control'=>'no-store']);
        });
    }

    private function run(Request $r,string $scope,callable $fn):JsonResponse
    {
        try{$p=$this->principal($r,$scope);return $this->db->transactional(function(Connection $db)use($p,$fn):JsonResponse{$t=$this->tenant($p);$this->tenantContext($db,$t);return $fn($db,$t,$p);});}
        catch(AccessDeniedHttpException $e){return $this->problem(403,$e->getMessage());}
        catch(Throwable){return $this->problem(503,'Customer operation is temporarily unavailable.');}
    }
    private function principal(Request $r,string $scope):AuthenticatedPrincipal
    {
        $p=$r->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if(!$p instanceof AuthenticatedPrincipal||!$p->hasScope($scope))throw new AccessDeniedHttpException('Required customer permission is missing.');
        return $p;
    }
    private function tenant(AuthenticatedPrincipal $p):string
    {
        if(!is_string($p->tenantId)||$p->tenantId==='')throw new AccessDeniedHttpException('Tenant context is missing.');
        return $p->tenantId;
    }
    private function tenantContext(Connection $db,string $t):void{$db->executeQuery("SELECT set_config('app.tenant_id',:t,true)",['t'=>$t]);}
    private function body(Request $r):array
    {
        $b=json_decode($r->getContent(),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($b)||array_is_list($b))throw new \InvalidArgumentException('A JSON object is required.');
        return $b;
    }
    private function customerInput(array $b):array
    {
        $type=$b['customerType']??'individual';if(!in_array($type,['individual','company'],true))throw new \DomainException('customerType must be individual or company.');
        $first=$this->string($b,'firstName',100);$last=$this->string($b,'lastName',100);$legal=$this->string($b,'legalName',180);
        if($type==='individual'&&(!$first||!$last))throw new \DomainException('firstName and lastName are required for individuals.');
        if($type==='company'&&!$legal)throw new \DomainException('legalName is required for companies.');
        $email=$this->string($b,'email',254);if($email!==null&&filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new \DomainException('email is invalid.');
        $locale=$this->string($b,'locale',10)??'de-DE';if(!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/',$locale))throw new \DomainException('locale is invalid.');
        return ['customerType'=>$type,'firstName'=>$first,'lastName'=>$last,'legalName'=>$legal,'email'=>$email===null?null:mb_strtolower($email),'phone'=>$this->string($b,'phone',40),'vatId'=>$this->string($b,'vatId',40),'locale'=>$locale];
    }
    private function addressInput(array $b):array
    {
        $type=$b['addressType']??'billing';if(!in_array($type,['billing','shipping','residential','business'],true))throw new \InvalidArgumentException('addressType is invalid.');
        $d=['addressType'=>$type,'label'=>$this->string($b,'label',80)];
        foreach(['line1'=>180,'postalCode'=>20,'city'=>120,'countryCode'=>2] as $field=>$max){$v=$b[$field]??null;if(!is_string($v)||trim($v)===''||mb_strlen(trim($v))>$max)throw new \InvalidArgumentException($field.' is required.');$d[$field]=trim($v);}
        $d['line2']=$this->string($b,'line2',180);$d['region']=$this->string($b,'region',120);$d['countryCode']=strtoupper($d['countryCode']);
        if(!preg_match('/^[A-Z]{2}$/',$d['countryCode']))throw new \InvalidArgumentException('countryCode must be ISO alpha-2.');
        $d['isDefault']=filter_var($b['isDefault']??false,FILTER_VALIDATE_BOOL);return $d;
    }
    private function string(array $b,string $field,int $max):?string
    {
        if(!isset($b[$field])||$b[$field]==='')return null;
        if(!is_string($b[$field])||trim($b[$field])===''||mb_strlen(trim($b[$field]))>$max)throw new \DomainException($field.' is invalid.');
        return trim($b[$field]);
    }
    private function load(Connection $db,string $t,string $id):?array
    {
        $c=$db->fetchAssociative('SELECT customer_id,customer_type,first_name,last_name,legal_name,email,phone,vat_id,locale,status,version,created_at,updated_at,archived_at FROM customer_records WHERE tenant_id=:t AND customer_id=:id',['t'=>$t,'id'=>$id]);
        if($c===false)return null;
        $c['addresses']=$db->fetchAllAssociative('SELECT address_id,address_type,label,line1,line2,postal_code,city,region,country_code,is_default FROM customer_addresses WHERE tenant_id=:t AND customer_id=:id AND deleted_at IS NULL ORDER BY is_default DESC,created_at',['t'=>$t,'id'=>$id]);
        $c['consents']=$db->fetchAllAssociative('SELECT purpose,granted,source,recorded_at FROM customer_consents WHERE tenant_id=:t AND customer_id=:id ORDER BY purpose',['t'=>$t,'id'=>$id]);return $c;
    }
    private function audit(Connection $db,string $t,string $id,string $actor,string $event,array $payload):void
    {
        $db->insert('customer_audit_events',['tenant_id'=>$t,'event_id'=>Uuid::v7()->toRfc4122(),'customer_id'=>$id,'actor_id'=>$actor,'event_type'=>$event,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
    }
    private function problem(int $status,string $detail):JsonResponse
    {
        return new JsonResponse(['type'=>'about:blank','title'=>match($status){400=>'Bad Request',403=>'Forbidden',404=>'Not Found',409=>'Conflict',422=>'Unprocessable Entity',default=>'Service Unavailable'},'status'=>$status,'detail'=>$detail],$status,['Content-Type'=>'application/problem+json','Cache-Control'=>'no-store']);
    }
}
