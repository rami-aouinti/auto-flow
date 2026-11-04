<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Domain\Entity\Manufacturer;
use App\Domain\Entity\VehicleGeneration;
use App\Domain\Entity\VehicleModel;
use App\Domain\Entity\VehicleVariant;
use PDO;
use Throwable;

final readonly class PostgresVehicleCatalogRepository implements VehicleCatalogRepositoryInterface
{
    public function __construct(private PostgresConnection $connection) {}

    public function listVehicles(array $filters, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new \InvalidArgumentException('Limit must be 1-100 and offset must be non-negative.');
        }
        $where = ["v.status = 'published'", "g.status = 'published'", "m.status = 'published'", "mf.status = 'published'"];
        $params = [];
        $columns = ['manufacturerId'=>'mf.manufacturer_id','modelId'=>'m.model_id','generationId'=>'g.generation_id',
            'fuelType'=>'v.fuel_type','powertrain'=>'v.powertrain','bodyStyle'=>'v.body_style','transmission'=>'v.transmission'];
        foreach ($columns as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = $column.' = :'.$key;
                $params[$key] = $filters[$key];
            }
        }
        foreach (['minPowerKw'=>['v.power_kw','>='],'maxPowerKw'=>['v.power_kw','<='],
            'minSeats'=>['v.seats','>='],'maxSeats'=>['v.seats','<=']] as $key => [$column,$operator]) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = $column.' '.$operator.' :'.$key;
                $params[$key] = (int) $filters[$key];
            }
        }
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $where[] = "(v.name ILIKE :q OR m.name ILIKE :q OR mf.name ILIKE :q)";
            $params['q'] = '%'.trim((string) $filters['q']).'%';
        }
        $from = $this->baseSelect();
        $condition = implode(' AND ', $where);
        $count = $this->connection->get()->prepare('SELECT COUNT(*) '.$from.' WHERE '.$condition);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sql = 'SELECT mf.manufacturer_id,mf.code AS brand_code,mf.name AS manufacturer_name,'
            .'m.model_id,m.name AS model_name,g.generation_id,g.name AS generation_name,'
            .'g.production_start_year,g.production_end_year,v.* '.$from.' WHERE '.$condition
            .' ORDER BY mf.name,m.name,g.production_start_year DESC NULLS LAST,v.name LIMIT :limit OFFSET :offset';
        $statement = $this->connection->get()->prepare($sql);
        foreach ($params as $key=>$value) {
            $statement->bindValue(':'.$key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit',$limit,PDO::PARAM_INT);
        $statement->bindValue(':offset',$offset,PDO::PARAM_INT);
        $statement->execute();
        return ['items'=>array_map($this->mapVehicle(...),$statement->fetchAll()),'total'=>$total];
    }

    public function getVehicle(string $variantId, bool $publishedOnly = true): ?array
    {
        $where = 'v.variant_id = :id';
        if ($publishedOnly) {
            $where .= " AND v.status='published' AND g.status='published' AND m.status='published' AND mf.status='published'";
        }
        $statement = $this->connection->get()->prepare(
            'SELECT mf.manufacturer_id,mf.code AS manufacturer_code,mf.name AS manufacturer_name,'
            .'m.model_id,m.name AS model_name,g.generation_id,g.name AS generation_name,'
            .'g.production_start_year,g.production_end_year,v.* '.$this->baseSelect().' WHERE '.$where
        );
        $statement->execute(['id'=>$variantId]);
        $row=$statement->fetch();
        return is_array($row) ? $this->mapVehicle($row) : null;
    }

    public function listManufacturers(bool $publishedOnly = true): array
    {
        $sql='SELECT manufacturer_id,code,name,status,created_at,updated_at FROM catalog_manufacturers';
        if ($publishedOnly) { $sql.=" WHERE status='published'"; }
        $sql.=' ORDER BY name';
        $rows = $this->connection->get()->query($sql)->fetchAll();

        return array_map(static fn (array $row): array => [
            'manufacturerId' => trim((string) $row['manufacturer_id']),
            'code' => $row['code'],
            'name' => $row['name'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ], $rows);
    }

    public function createManufacturer(Manufacturer $manufacturer): void
    {
        $this->transactionalEvent('catalog.manufacturer.created',$manufacturer->id->value,[
            'manufacturerId'=>$manufacturer->id->value,'code'=>$manufacturer->code,'name'=>$manufacturer->name,'status'=>$manufacturer->status->value
        ],function(PDO $db) use ($manufacturer): void {
            $s=$db->prepare('INSERT INTO catalog_manufacturers(manufacturer_id,code,name,status) VALUES(:id,:code,:name,:status)');
            $s->execute(['id'=>$manufacturer->id->value,'code'=>$manufacturer->code,'name'=>$manufacturer->name,'status'=>$manufacturer->status->value]);
        });
    }

    public function createModel(VehicleModel $model): void
    {
        $this->transactionalEvent('catalog.model.created',$model->id->value,[
            'modelId'=>$model->id->value,'manufacturerId'=>$model->manufacturerId->value,'name'=>$model->name
        ],function(PDO $db) use ($model): void {
            $s=$db->prepare('INSERT INTO catalog_models(model_id,manufacturer_id,name,normalized_name) VALUES(:id,:manufacturer,:name,:normalized)');
            $s->execute(['id'=>$model->id->value,'manufacturer'=>$model->manufacturerId->value,'name'=>$model->name,'normalized'=>$model->normalizedName]);
        });
    }

    public function createGeneration(VehicleGeneration $generation): void
    {
        $this->transactionalEvent('catalog.generation.created',$generation->id->value,[
            'generationId'=>$generation->id->value,'modelId'=>$generation->modelId->value,'name'=>$generation->name,
            'productionStartYear'=>$generation->productionStartYear,'productionEndYear'=>$generation->productionEndYear
        ],function(PDO $db) use ($generation): void {
            $s=$db->prepare('INSERT INTO catalog_generations(generation_id,model_id,name,production_start_year,production_end_year) VALUES(:id,:model,:name,:start,:end)');
            $s->execute(['id'=>$generation->id->value,'model'=>$generation->modelId->value,'name'=>$generation->name,'start'=>$generation->productionStartYear,'end'=>$generation->productionEndYear]);
        });
    }

    public function createVariant(VehicleVariant $v): void
    {
        $payload=['variantId'=>$v->id->value,'generationId'=>$v->generationId->value,'name'=>$v->name,
            'manufacturerCode'=>$v->manufacturerCode,'fuelType'=>$v->fuelType,'powertrain'=>$v->powertrain,
            'bodyStyle'=>$v->bodyStyle,'transmission'=>$v->transmission,'powerKw'=>$v->powerKw,
            'displacementCc'=>$v->displacementCc,'seats'=>$v->seats,'doors'=>$v->doors,
            'curbWeightKg'=>$v->curbWeightKg,'lengthMm'=>$v->lengthMm,'widthMm'=>$v->widthMm,
            'heightMm'=>$v->heightMm,'co2EmissionGPerKm'=>$v->co2EmissionGPerKm,'mediaReferences'=>$v->mediaReferences];
        $this->transactionalEvent('catalog.vehicle_variant.created',$v->id->value,$payload,function(PDO $db) use ($v): void {
            $s=$db->prepare('INSERT INTO catalog_vehicle_variants(variant_id,generation_id,manufacturer_code,name,fuel_type,powertrain,body_style,transmission,power_kw,displacement_cc,seats,doors,curb_weight_kg,length_mm,width_mm,height_mm,co2_emission_g_per_km,media_references,status) VALUES(:id,:generation,:code,:name,:fuel,:powertrain,:body,:transmission,:power,:cc,:seats,:doors,:weight,:length,:width,:height,:co2,:media,\'draft\')');
            $s->execute(['id'=>$v->id->value,'generation'=>$v->generationId->value,'code'=>$v->manufacturerCode,'name'=>$v->name,
                'fuel'=>$v->fuelType,'powertrain'=>$v->powertrain,'body'=>$v->bodyStyle,'transmission'=>$v->transmission,
                'power'=>$v->powerKw,'cc'=>$v->displacementCc,'seats'=>$v->seats,'doors'=>$v->doors,'weight'=>$v->curbWeightKg,
                'length'=>$v->lengthMm,'width'=>$v->widthMm,'height'=>$v->heightMm,'co2'=>$v->co2EmissionGPerKm,
                'media'=>json_encode($v->mediaReferences,JSON_THROW_ON_ERROR)]);
        });
    }

    public function publishVariant(string $variantId): bool
    {
        $db=$this->connection->get();
        $db->beginTransaction();
        try {
            $find=$db->prepare('SELECT g.generation_id,m.model_id,mf.manufacturer_id,v.status AS variant_status,g.status AS generation_status,m.status AS model_status,mf.status AS manufacturer_status FROM catalog_vehicle_variants v JOIN catalog_generations g ON g.generation_id=v.generation_id JOIN catalog_models m ON m.model_id=g.model_id JOIN catalog_manufacturers mf ON mf.manufacturer_id=m.manufacturer_id WHERE v.variant_id=:id FOR UPDATE OF v');
            $find->execute(['id'=>$variantId]);
            $row=$find->fetch();
            if (!is_array($row) || in_array('archived', [$row['variant_status'], $row['generation_status'], $row['model_status'], $row['manufacturer_status']], true)) {
                $db->rollBack();
                return false;
            }
            foreach ([
                ['catalog_manufacturers','manufacturer_id',$row['manufacturer_id']],
                ['catalog_models','model_id',$row['model_id']],
                ['catalog_generations','generation_id',$row['generation_id']]
            ] as [$table,$column,$id]) {
                $db->prepare("UPDATE {$table} SET status='published',updated_at=CURRENT_TIMESTAMP WHERE {$column}=:id AND status<>'archived'")->execute(['id'=>$id]);
            }
            $db->prepare("UPDATE catalog_vehicle_variants SET status='published',updated_at=CURRENT_TIMESTAMP WHERE variant_id=:id")->execute(['id'=>$variantId]);
            $eventId=bin2hex(random_bytes(16));
            $event=['eventId'=>$eventId,'eventType'=>'catalog.vehicle_variant.published','schemaVersion'=>1,
                'aggregateId'=>$variantId,'occurredAt'=>(new \DateTimeImmutable())->format(DATE_ATOM),
                'payload'=>['variantId'=>$variantId]];
            $insert=$db->prepare('INSERT INTO catalog_outbox(event_id,aggregate_id,event_type,schema_version,payload) VALUES(:id,:aggregate,:type,1,:payload)');
            $insert->execute(['id'=>$eventId,'aggregate'=>$variantId,'type'=>'catalog.vehicle_variant.published','payload'=>json_encode($event,JSON_THROW_ON_ERROR)]);
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    private function baseSelect(): string
    {
        return 'FROM catalog_vehicle_variants v JOIN catalog_generations g ON g.generation_id=v.generation_id '
            .'JOIN catalog_models m ON m.model_id=g.model_id JOIN catalog_manufacturers mf ON mf.manufacturer_id=m.manufacturer_id';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapVehicle(array $row): array
    {
        $media=$row['media_references'] ?? '[]';
        return [
            'variantId'=>$row['variant_id'],'manufacturer'=>['manufacturerId'=>$row['manufacturer_id'],'code'=>$row['brand_code'],'name'=>$row['manufacturer_name']],
            'model'=>['modelId'=>$row['model_id'],'name'=>$row['model_name']],
            'generation'=>['generationId'=>$row['generation_id'],'name'=>$row['generation_name'],'productionStartYear'=>$row['production_start_year']===null?null:(int)$row['production_start_year'],'productionEndYear'=>$row['production_end_year']===null?null:(int)$row['production_end_year']],
            'manufacturerCode'=>$row['manufacturer_code'],'name'=>$row['name'],'fuelType'=>$row['fuel_type'],'powertrain'=>$row['powertrain'],
            'bodyStyle'=>$row['body_style'],'transmission'=>$row['transmission'],'powerKw'=>$row['power_kw']===null?null:(int)$row['power_kw'],
            'displacementCc'=>$row['displacement_cc']===null?null:(int)$row['displacement_cc'],'seats'=>$row['seats']===null?null:(int)$row['seats'],
            'doors'=>$row['doors']===null?null:(int)$row['doors'],'curbWeightKg'=>$row['curb_weight_kg']===null?null:(int)$row['curb_weight_kg'],
            'lengthMm'=>$row['length_mm']===null?null:(int)$row['length_mm'],'widthMm'=>$row['width_mm']===null?null:(int)$row['width_mm'],
            'heightMm'=>$row['height_mm']===null?null:(int)$row['height_mm'],'co2EmissionGPerKm'=>$row['co2_emission_g_per_km']===null?null:(int)$row['co2_emission_g_per_km'],
            'mediaReferences'=>is_string($media)?(json_decode($media,true)?:[]):$media,'status'=>$row['status'],
            'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']
        ];
    }

    /** @param array<string,mixed> $payload */
    private function transactionalEvent(string $type,string $aggregateId,array $payload,callable $operation): void
    {
        $db=$this->connection->get();
        $db->beginTransaction();
        try {
            $operation($db);
            $eventId=bin2hex(random_bytes(16));
            $event=['eventId'=>$eventId,'eventType'=>$type,'schemaVersion'=>1,'aggregateId'=>$aggregateId,
                'occurredAt'=>(new \DateTimeImmutable())->format(DATE_ATOM),'payload'=>$payload];
            $s=$db->prepare('INSERT INTO catalog_outbox(event_id,aggregate_id,event_type,schema_version,payload) VALUES(:id,:aggregate,:type,1,:payload)');
            $s->execute(['id'=>$eventId,'aggregate'=>$aggregateId,'type'=>$type,'payload'=>json_encode($event,JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }
}