-- AutoFlow coherent local demo dataset.
-- Safe to re-run: stable identifiers + ON CONFLICT DO NOTHING.
-- Run only against a local development database. All people and VINs are fictional.
-- This seeds persisted business data; it deliberately does NOT enqueue Outbox events.

BEGIN;

-- ---------------------------------------------------------------------------
-- Vehicle catalogue (same stable IDs as the existing catalogue demo fixture)
-- ---------------------------------------------------------------------------
INSERT INTO catalog_manufacturers(manufacturer_id,code,name,status) VALUES
('10000000000000000000000000000001','VOLKSWAGEN','Volkswagen','published'),
('10000000000000000000000000000002','BMW','BMW','published'),
('10000000000000000000000000000003','MERCEDES','Mercedes-Benz','published'),
('10000000000000000000000000000004','AUDI','Audi','published')
ON CONFLICT (manufacturer_id) DO NOTHING;

INSERT INTO catalog_models(model_id,manufacturer_id,name,normalized_name,status) VALUES
('20000000000000000000000000000001','10000000000000000000000000000001','Golf','golf','published'),
('20000000000000000000000000000002','10000000000000000000000000000001','ID.4','id.4','published'),
('20000000000000000000000000000003','10000000000000000000000000000002','3 Series','3 series','published'),
('20000000000000000000000000000004','10000000000000000000000000003','C-Class','c-class','published'),
('20000000000000000000000000000005','10000000000000000000000000000004','A4','a4','published')
ON CONFLICT (model_id) DO NOTHING;

INSERT INTO catalog_generations(generation_id,model_id,name,production_start_year,production_end_year,status) VALUES
('30000000000000000000000000000001','20000000000000000000000000000001','VIII',2020,NULL,'published'),
('30000000000000000000000000000002','20000000000000000000000000000002','I',2021,NULL,'published'),
('30000000000000000000000000000003','20000000000000000000000000000003','G20',2018,NULL,'published'),
('30000000000000000000000000000004','20000000000000000000000000000004','W206',2021,NULL,'published'),
('30000000000000000000000000000005','20000000000000000000000000000005','B9',2015,2024,'published')
ON CONFLICT (generation_id) DO NOTHING;

INSERT INTO catalog_vehicle_variants(
 variant_id,generation_id,manufacturer_code,name,fuel_type,powertrain,body_style,transmission,
 power_kw,displacement_cc,seats,doors,curb_weight_kg,length_mm,width_mm,height_mm,
 co2_emission_g_per_km,media_references,status
) VALUES
('40000000000000000000000000000001','30000000000000000000000000000001','VW-GOLF-20-TDI','Golf 2.0 TDI 110 kW','diesel','ice','hatchback','automatic',110,1968,5,5,1450,4284,1789,1456,121,'[]','published'),
('40000000000000000000000000000002','30000000000000000000000000000001','VW-GOLF-15-TSI','Golf 1.5 eTSI 110 kW','petrol','mhev','hatchback','automatic',110,1498,5,5,1370,4284,1789,1456,118,'[]','published'),
('40000000000000000000000000000003','30000000000000000000000000000002','VW-ID4-77KWH','ID.4 Pro 210 kW','electric','bev','suv','single_speed',210,NULL,5,5,2124,4584,1852,1634,0,'[]','published'),
('40000000000000000000000000000004','30000000000000000000000000000003','BMW-320D','320d 140 kW','diesel','ice','sedan','automatic',140,1995,5,4,1615,4713,1827,1440,125,'[]','published'),
('40000000000000000000000000000005','30000000000000000000000000000003','BMW-330E','330e Plug-in Hybrid','plug_in_hybrid','phev','sedan','automatic',215,1998,5,4,1810,4713,1827,1440,30,'[]','published'),
('40000000000000000000000000000006','30000000000000000000000000000004','MB-C220D','C 220 d 147 kW','diesel','ice','sedan','automatic',147,1993,5,4,1765,4751,1820,1438,127,'[]','published'),
('40000000000000000000000000000007','30000000000000000000000000000005','AUDI-A4-40TDI','A4 40 TDI 150 kW','diesel','ice','estate','automatic',150,1968,5,5,1645,4762,1847,1435,128,'[]','published')
ON CONFLICT (variant_id) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Customers (tenant-demo)
-- ---------------------------------------------------------------------------
INSERT INTO customer_records(
 tenant_id,customer_id,customer_type,first_name,last_name,legal_name,email,email_normalized,
 phone,locale,status,created_by,updated_by
) VALUES
('tenant-demo','a0000000-0000-4000-8000-000000000001','individual','Lena','Schneider',NULL,'lena.schneider@example.test','lena.schneider@example.test','+49 30 5550 0101','de-DE','active','demo-fixtures','demo-fixtures'),
('tenant-demo','a0000000-0000-4000-8000-000000000002','individual','Jonas','Weber',NULL,'jonas.weber@example.test','jonas.weber@example.test','+49 30 5550 0102','de-DE','active','demo-fixtures','demo-fixtures'),
('tenant-demo','a0000000-0000-4000-8000-000000000003','individual','Mia','Fischer',NULL,'mia.fischer@example.test','mia.fischer@example.test','+49 30 5550 0103','de-DE','active','demo-fixtures','demo-fixtures'),
('tenant-demo','a0000000-0000-4000-8000-000000000004','company',NULL,NULL,'Nordlicht Mobility GmbH','fleet@example.test','fleet@example.test','+49 40 5550 0104','de-DE','active','demo-fixtures','demo-fixtures'),
('tenant-demo','a0000000-0000-4000-8000-000000000005','individual','Noah','Wagner',NULL,'noah.wagner@example.test','noah.wagner@example.test','+49 89 5550 0105','de-DE','active','demo-fixtures','demo-fixtures')
ON CONFLICT (tenant_id,customer_id) DO NOTHING;

INSERT INTO customer_addresses(tenant_id,address_id,customer_id,address_type,label,line1,postal_code,city,region,country_code,is_default) VALUES
('tenant-demo','a1000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','billing','Privat','Friedrichstrasse 120','10117','Berlin','Berlin','DE',true),
('tenant-demo','a1000000-0000-4000-8000-000000000002','a0000000-0000-4000-8000-000000000001','shipping','Lieferadresse','Friedrichstrasse 120','10117','Berlin','Berlin','DE',true),
('tenant-demo','a1000000-0000-4000-8000-000000000003','a0000000-0000-4000-8000-000000000002','billing','Privat','Venloer Strasse 88','50672','Köln','Nordrhein-Westfalen','DE',true),
('tenant-demo','a1000000-0000-4000-8000-000000000004','a0000000-0000-4000-8000-000000000003','billing','Privat','Leopoldstrasse 42','80802','München','Bayern','DE',true),
('tenant-demo','a1000000-0000-4000-8000-000000000005','a0000000-0000-4000-8000-000000000004','business','Firmenadresse','Am Sandtorkai 25','20457','Hamburg','Hamburg','DE',true),
('tenant-demo','a1000000-0000-4000-8000-000000000006','a0000000-0000-4000-8000-000000000005','billing','Privat','Königsallee 14','40212','Düsseldorf','Nordrhein-Westfalen','DE',true)
ON CONFLICT (tenant_id,address_id) DO NOTHING;

INSERT INTO customer_consents(tenant_id,customer_id,purpose,granted,source) VALUES
('tenant-demo','a0000000-0000-4000-8000-000000000001','marketing_email',true,'demo_seed'),
('tenant-demo','a0000000-0000-4000-8000-000000000001','product_updates',true,'demo_seed'),
('tenant-demo','a0000000-0000-4000-8000-000000000002','marketing_email',false,'demo_seed'),
('tenant-demo','a0000000-0000-4000-8000-000000000003','marketing_email',true,'demo_seed'),
('tenant-demo','a0000000-0000-4000-8000-000000000004','fleet_contact',true,'demo_seed')
ON CONFLICT (tenant_id,customer_id,purpose) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Pricing quotes and offers. Quote IDs match the existing Pricing demo set.
-- ---------------------------------------------------------------------------
INSERT INTO pricing_policies(
 policy_id,tenant_id,version,status,minimum_duration_months,maximum_duration_months,
 minimum_annual_rate_bps,maximum_annual_rate_bps,quote_validity_days,calculation_version,created_by,published_at
) VALUES (
 '123e4567-e89b-42d3-a456-426614174000','tenant-demo',1,'active',12,72,0,2000,7,
 'lease-annuity-v1','demo-fixtures',CURRENT_TIMESTAMP
)
ON CONFLICT (policy_id) DO NOTHING;

INSERT INTO pricing_quotes(
 quote_id,tenant_id,created_by,vehicle_id,monthly_amount_minor,total_installments_minor,total_cost_minor,
 residual_value_minor,currency,duration_months,annual_interest_rate_basis_points,calculation_version,
 vehicle_price_minor,down_payment_minor,expires_at,policy_id,policy_version
) VALUES
('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','tenant-demo','demo-fixtures','40000000000000000000000000000004',68638,3294624,3794624,800000,'EUR',48,425,'lease-annuity-v1',4200000,500000,CURRENT_TIMESTAMP + INTERVAL '7 days','123e4567-e89b-42d3-a456-426614174000',1),
('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','tenant-demo','demo-fixtures','40000000000000000000000000000003',58935,2828880,3328880,950000,'EUR',48,425,'lease-annuity-v1',3899000,500000,CURRENT_TIMESTAMP + INTERVAL '7 days','123e4567-e89b-42d3-a456-426614174000',1),
('cccccccccccccccccccccccccccccccc','tenant-demo','demo-fixtures','40000000000000000000000000000005',58801,2822448,3222448,720000,'EUR',48,425,'lease-annuity-v1',3599000,400000,CURRENT_TIMESTAMP + INTERVAL '7 days','123e4567-e89b-42d3-a456-426614174000',1),
('dddddddddddddddddddddddddddddddd','tenant-demo','demo-fixtures','40000000000000000000000000000007',67657,3247536,3747536,850000,'EUR',48,425,'lease-annuity-v1',4199000,500000,CURRENT_TIMESTAMP + INTERVAL '7 days','123e4567-e89b-42d3-a456-426614174000',1),
('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','tenant-demo','demo-fixtures','40000000000000000000000000000006',75805,3638640,4138640,780000,'EUR',48,425,'lease-annuity-v1',4499000,500000,CURRENT_TIMESTAMP + INTERVAL '7 days','123e4567-e89b-42d3-a456-426614174000',1),
('ffffffffffffffffffffffffffffffff','tenant-demo','demo-fixtures','40000000000000000000000000000001',34145,1638960,2138960,1200000,'EUR',48,450,'lease-annuity-v1',3000000,500000,CURRENT_TIMESTAMP + INTERVAL '7 days','123e4567-e89b-42d3-a456-426614174000',1),
('77777777777777777777777777777777','tenant-demo','demo-fixtures','40000000000000000000000000000002',41745,1502820,1902820,750000,'EUR',36,350,'lease-annuity-v1',2500000,400000,CURRENT_TIMESTAMP + INTERVAL '7 days','123e4567-e89b-42d3-a456-426614174000',1)
ON CONFLICT (quote_id) DO NOTHING;

INSERT INTO offers(tenant_id,offer_id,created_by,status,quote_id,pricing_snapshot,created_at,expires_at) VALUES
('tenant-demo','b0000000-0000-4000-8000-000000000001','demo-fixtures','accepted','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','{"currency":"EUR","monthlyAmount":"686.38","durationMonths":48,"vehicleId":"40000000000000000000000000000004"}',CURRENT_TIMESTAMP - INTERVAL '18 days',CURRENT_TIMESTAMP + INTERVAL '12 days'),
('tenant-demo','b0000000-0000-4000-8000-000000000002','demo-fixtures','published','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','{"currency":"EUR","monthlyAmount":"589.35","durationMonths":48,"vehicleId":"40000000000000000000000000000003"}',CURRENT_TIMESTAMP - INTERVAL '4 days',CURRENT_TIMESTAMP + INTERVAL '3 days'),
('tenant-demo','b0000000-0000-4000-8000-000000000003','demo-fixtures','accepted','cccccccccccccccccccccccccccccccc','{"currency":"EUR","monthlyAmount":"588.01","durationMonths":48,"vehicleId":"40000000000000000000000000000005"}',CURRENT_TIMESTAMP - INTERVAL '8 days',CURRENT_TIMESTAMP + INTERVAL '6 days'),
('tenant-demo','b0000000-0000-4000-8000-000000000004','demo-fixtures','draft','dddddddddddddddddddddddddddddddd','{"currency":"EUR","monthlyAmount":"676.57","durationMonths":48,"vehicleId":"40000000000000000000000000000007"}',CURRENT_TIMESTAMP - INTERVAL '1 day',CURRENT_TIMESTAMP + INTERVAL '6 days'),
('tenant-demo','b0000000-0000-4000-8000-000000000005','demo-fixtures','rejected','eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','{"currency":"EUR","monthlyAmount":"758.05","durationMonths":48,"vehicleId":"40000000000000000000000000000006"}',CURRENT_TIMESTAMP - INTERVAL '12 days',CURRENT_TIMESTAMP + INTERVAL '2 days')
ON CONFLICT (tenant_id,offer_id) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Orders: each row links to a seeded customer, offer, quote and catalogue ID.
-- ---------------------------------------------------------------------------
INSERT INTO orders(
 tenant_id,order_id,created_by,offer_id,quote_id,vehicle_id,status,
 offer_snapshot,quote_snapshot,vehicle_snapshot,created_at,updated_at,submitted_at,decided_at,
 customer_id,customer_version,customer_snapshot
) VALUES
('tenant-demo','c0000000-0000-4000-8000-000000000001','demo-fixtures','b0000000-0000-4000-8000-000000000001','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','40000000000000000000000000000004','completed',
 '{"offerId":"b0000000-0000-4000-8000-000000000001","status":"accepted"}',
 '{"quoteId":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","currency":"EUR","monthlyAmount":"686.38","durationMonths":48,"totalCost":"37946.24"}',
 '{"manufacturer":"BMW","model":"3 Series","variant":"320d 140 kW","vehicleId":"40000000000000000000000000000004"}',
 CURRENT_TIMESTAMP - INTERVAL '30 days',CURRENT_TIMESTAMP - INTERVAL '5 days',CURRENT_TIMESTAMP - INTERVAL '29 days',CURRENT_TIMESTAMP - INTERVAL '28 days',
 'a0000000-0000-4000-8000-000000000001',1,'{"customerId":"a0000000-0000-4000-8000-000000000001","displayName":"Lena Schneider","email":"lena.schneider@example.test"}'),
('tenant-demo','c0000000-0000-4000-8000-000000000002','demo-fixtures','b0000000-0000-4000-8000-000000000002','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','40000000000000000000000000000003','accepted',
 '{"offerId":"b0000000-0000-4000-8000-000000000002","status":"accepted"}',
 '{"quoteId":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","currency":"EUR","monthlyAmount":"589.35","durationMonths":48,"totalCost":"33288.80"}',
 '{"manufacturer":"Volkswagen","model":"ID.4","variant":"ID.4 Pro 210 kW","vehicleId":"40000000000000000000000000000003"}',
 CURRENT_TIMESTAMP - INTERVAL '12 days',CURRENT_TIMESTAMP - INTERVAL '1 day',CURRENT_TIMESTAMP - INTERVAL '11 days',CURRENT_TIMESTAMP - INTERVAL '10 days',
 'a0000000-0000-4000-8000-000000000002',1,'{"customerId":"a0000000-0000-4000-8000-000000000002","displayName":"Jonas Weber","email":"jonas.weber@example.test"}'),
('tenant-demo','c0000000-0000-4000-8000-000000000003','demo-fixtures','b0000000-0000-4000-8000-000000000003','cccccccccccccccccccccccccccccccc','40000000000000000000000000000005','under_review',
 '{"offerId":"b0000000-0000-4000-8000-000000000003","status":"accepted"}',
 '{"quoteId":"cccccccccccccccccccccccccccccccc","currency":"EUR","monthlyAmount":"588.01","durationMonths":48,"totalCost":"32224.48"}',
 '{"manufacturer":"BMW","model":"3 Series","variant":"330e Plug-in Hybrid","vehicleId":"40000000000000000000000000000005"}',
 CURRENT_TIMESTAMP - INTERVAL '7 days',CURRENT_TIMESTAMP - INTERVAL '2 days',CURRENT_TIMESTAMP - INTERVAL '6 days',NULL,
 'a0000000-0000-4000-8000-000000000003',1,'{"customerId":"a0000000-0000-4000-8000-000000000003","displayName":"Mia Fischer","email":"mia.fischer@example.test"}'),
('tenant-demo','c0000000-0000-4000-8000-000000000004','demo-fixtures','b0000000-0000-4000-8000-000000000004','dddddddddddddddddddddddddddddddd','40000000000000000000000000000007','submitted',
 '{"offerId":"b0000000-0000-4000-8000-000000000004","status":"draft"}',
 '{"quoteId":"dddddddddddddddddddddddddddddddd","currency":"EUR","monthlyAmount":"676.57","durationMonths":48,"totalCost":"37475.36"}',
 '{"manufacturer":"Audi","model":"A4","variant":"A4 40 TDI 150 kW","vehicleId":"40000000000000000000000000000007"}',
 CURRENT_TIMESTAMP - INTERVAL '2 days',CURRENT_TIMESTAMP - INTERVAL '1 day',CURRENT_TIMESTAMP - INTERVAL '1 day',NULL,
 'a0000000-0000-4000-8000-000000000004',1,'{"customerId":"a0000000-0000-4000-8000-000000000004","displayName":"Nordlicht Mobility GmbH","email":"fleet@example.test"}'),
('tenant-demo','c0000000-0000-4000-8000-000000000005','demo-fixtures','b0000000-0000-4000-8000-000000000005','eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','40000000000000000000000000000006','rejected',
 '{"offerId":"b0000000-0000-4000-8000-000000000005","status":"rejected"}',
 '{"quoteId":"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","currency":"EUR","monthlyAmount":"758.05","durationMonths":48,"totalCost":"41386.40"}',
 '{"manufacturer":"Mercedes-Benz","model":"C-Class","variant":"C 220 d 147 kW","vehicleId":"40000000000000000000000000000006"}',
 CURRENT_TIMESTAMP - INTERVAL '20 days',CURRENT_TIMESTAMP - INTERVAL '15 days',CURRENT_TIMESTAMP - INTERVAL '19 days',CURRENT_TIMESTAMP - INTERVAL '15 days',
 'a0000000-0000-4000-8000-000000000005',1,'{"customerId":"a0000000-0000-4000-8000-000000000005","displayName":"Noah Wagner","email":"noah.wagner@example.test"}')
ON CONFLICT (tenant_id,order_id) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Payments and invoices. Stripe IDs are explicitly demo placeholders.
-- ---------------------------------------------------------------------------
INSERT INTO payments(
 tenant_id,payment_id,order_id,created_by,provider,provider_payment_id,amount_minor,currency,status,
 amount_refunded_minor,failure_code,created_at,updated_at,billing_snapshot
) VALUES
('tenant-demo','d0000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000001','demo-fixtures','demo','demo_pi_captured_001',3294624,'EUR','captured',0,NULL,CURRENT_TIMESTAMP - INTERVAL '24 days',CURRENT_TIMESTAMP - INTERVAL '23 days','{"customerName":"Lena Schneider","email":"lena.schneider@example.test","taxRateBasisPoints":1900}'),
('tenant-demo','d0000000-0000-4000-8000-000000000002','c0000000-0000-4000-8000-000000000002','demo-fixtures','demo','demo_pi_pending_002',2828880,'EUR','requires_action',0,NULL,CURRENT_TIMESTAMP - INTERVAL '3 days',CURRENT_TIMESTAMP - INTERVAL '3 days','{"customerName":"Jonas Weber","email":"jonas.weber@example.test","taxRateBasisPoints":1900}'),
('tenant-demo','d0000000-0000-4000-8000-000000000003','c0000000-0000-4000-8000-000000000003','demo-fixtures','demo','demo_pi_failed_003',2822448,'EUR','failed',0,'authentication_required',CURRENT_TIMESTAMP - INTERVAL '2 days',CURRENT_TIMESTAMP - INTERVAL '2 days','{"customerName":"Mia Fischer","email":"mia.fischer@example.test","taxRateBasisPoints":1900}'),
('tenant-demo','d0000000-0000-4000-8000-000000000004','c0000000-0000-4000-8000-000000000004','demo-fixtures','demo','demo_pi_pending_004',3247536,'EUR','pending',0,NULL,CURRENT_TIMESTAMP - INTERVAL '1 day',CURRENT_TIMESTAMP - INTERVAL '1 day','{"customerName":"Nordlicht Mobility GmbH","email":"fleet@example.test","taxRateBasisPoints":1900}')
ON CONFLICT (tenant_id,payment_id) DO NOTHING;

INSERT INTO billing_invoices(
 tenant_id,invoice_id,invoice_number,payment_id,order_id,currency,gross_minor,net_minor,tax_minor,
 tax_rate_basis_points,status,billing_snapshot,source_event_id,issued_at,due_at,paid_at
) VALUES
('tenant-demo','e0000000-0000-4000-8000-000000000001','AF-DEMO-2026-0001','d0000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000001','EUR',3294624,2768583,526041,1900,'paid',
 '{"customerName":"Lena Schneider","email":"lena.schneider@example.test","description":"BMW 320d Leasing"}',
 'e1000000-0000-4000-8000-000000000001',CURRENT_TIMESTAMP - INTERVAL '23 days',CURRENT_TIMESTAMP - INTERVAL '9 days',CURRENT_TIMESTAMP - INTERVAL '22 days')
ON CONFLICT (tenant_id,invoice_id) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Contracts and lifecycle metadata
-- ---------------------------------------------------------------------------
INSERT INTO contract_records(
 tenant_id,contract_id,order_id,customer_id,contract_type,contract_version,status,legal_snapshot,
 document_checksum,signature_provider,provider_envelope_id,created_by,created_at,updated_at,prepared_at,signed_at
) VALUES
('tenant-demo','f0000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001',
 'vehicle_lease',1,'signed',
 '{"customer":{"name":"Lena Schneider","email":"lena.schneider@example.test"},"vehicle":{"manufacturer":"BMW","model":"3 Series","variant":"320d 140 kW"},"commercial":{"currency":"EUR","monthlyAmount":"686.38","durationMonths":48},"demo":true}',
 repeat('a',64),'demo','demo-envelope-signed-001','demo-fixtures',CURRENT_TIMESTAMP - INTERVAL '22 days',CURRENT_TIMESTAMP - INTERVAL '20 days',CURRENT_TIMESTAMP - INTERVAL '21 days',CURRENT_TIMESTAMP - INTERVAL '20 days'),
('tenant-demo','f0000000-0000-4000-8000-000000000002','c0000000-0000-4000-8000-000000000002','a0000000-0000-4000-8000-000000000002',
 'vehicle_lease',1,'awaiting_signature',
 '{"customer":{"name":"Jonas Weber","email":"jonas.weber@example.test"},"vehicle":{"manufacturer":"Volkswagen","model":"ID.4","variant":"ID.4 Pro 210 kW"},"commercial":{"currency":"EUR","monthlyAmount":"589.35","durationMonths":48},"demo":true}',
 repeat('b',64),'demo','demo-envelope-pending-002','demo-fixtures',CURRENT_TIMESTAMP - INTERVAL '8 days',CURRENT_TIMESTAMP - INTERVAL '1 day',CURRENT_TIMESTAMP - INTERVAL '2 days',NULL),
('tenant-demo','f0000000-0000-4000-8000-000000000003','c0000000-0000-4000-8000-000000000003','a0000000-0000-4000-8000-000000000003',
 'vehicle_lease',1,'draft',
 '{"customer":{"name":"Mia Fischer","email":"mia.fischer@example.test"},"vehicle":{"manufacturer":"BMW","model":"3 Series","variant":"330e Plug-in Hybrid"},"commercial":{"currency":"EUR","monthlyAmount":"588.01","durationMonths":48},"demo":true}',
 NULL,NULL,NULL,'demo-fixtures',CURRENT_TIMESTAMP - INTERVAL '3 days',CURRENT_TIMESTAMP - INTERVAL '3 days',NULL,NULL)
ON CONFLICT (tenant_id,contract_id) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Inventory, physical stock reservations and fulfilment
-- ---------------------------------------------------------------------------
INSERT INTO inventory_units(
 tenant_id,inventory_unit_id,vehicle_variant_id,vin,vin_normalized,dealer_id,location_code,country_code,
 status,condition,mileage_km,first_registration_date,acquired_at,created_by,updated_by
) VALUES
('tenant-demo','10000000-0000-4000-8000-000000000001','40000000000000000000000000000004'::uuid,'WVWDEMA0000000001','WVWDEMA0000000001','20000000-0000-4000-8000-000000000001','BER-01','DE','delivered','new',0,NULL,CURRENT_TIMESTAMP - INTERVAL '60 days','demo-fixtures','demo-fixtures'),
('tenant-demo','10000000-0000-4000-8000-000000000002','40000000000000000000000000000003'::uuid,'WVWDEMA0000000002','WVWDEMA0000000002','20000000-0000-4000-8000-000000000002','CGN-01','DE','blocked','new',0,NULL,CURRENT_TIMESTAMP - INTERVAL '30 days','demo-fixtures','demo-fixtures'),
('tenant-demo','10000000-0000-4000-8000-000000000003','40000000000000000000000000000005'::uuid,'WBADEMA0000000003','WBADEMA0000000003','20000000-0000-4000-8000-000000000003','MUC-01','DE','available','new',0,NULL,CURRENT_TIMESTAMP - INTERVAL '20 days','demo-fixtures','demo-fixtures'),
('tenant-demo','10000000-0000-4000-8000-000000000004','40000000000000000000000000000007'::uuid,'WAUDEMA0000000004','WAUDEMA0000000004','20000000-0000-4000-8000-000000000004','HAM-01','DE','available','new',0,NULL,CURRENT_TIMESTAMP - INTERVAL '12 days','demo-fixtures','demo-fixtures'),
('tenant-demo','10000000-0000-4000-8000-000000000005','40000000000000000000000000000006'::uuid,'WDDDEMA0000000005','WDDDEMA0000000005','20000000-0000-4000-8000-000000000005','DUS-01','DE','maintenance','used',18000,'2024-02-01',CURRENT_TIMESTAMP - INTERVAL '90 days','demo-fixtures','demo-fixtures')
ON CONFLICT (tenant_id,inventory_unit_id) DO NOTHING;

INSERT INTO vehicle_reservations(
 tenant_id,reservation_id,vehicle_unit_id,vehicle_variant_id,order_id,status,expires_at,confirmed_at,fulfilled_at
) VALUES
('tenant-demo','11000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000001','40000000000000000000000000000004'::uuid,'c0000000-0000-4000-8000-000000000001','fulfilled',CURRENT_TIMESTAMP - INTERVAL '20 days',CURRENT_TIMESTAMP - INTERVAL '21 days',CURRENT_TIMESTAMP - INTERVAL '5 days'),
('tenant-demo','11000000-0000-4000-8000-000000000002','10000000-0000-4000-8000-000000000002','40000000000000000000000000000003'::uuid,'c0000000-0000-4000-8000-000000000002','confirmed',CURRENT_TIMESTAMP + INTERVAL '3 days',CURRENT_TIMESTAMP - INTERVAL '1 day',NULL),
('tenant-demo','11000000-0000-4000-8000-000000000003','10000000-0000-4000-8000-000000000003','40000000000000000000000000000005'::uuid,'c0000000-0000-4000-8000-000000000003','held',CURRENT_TIMESTAMP + INTERVAL '20 hours',NULL,NULL)
ON CONFLICT (tenant_id,reservation_id) DO NOTHING;

INSERT INTO fulfillment_records(
 tenant_id,fulfillment_id,order_id,status,payment_captured_at,contract_signed_at,scheduled_for,carrier,
 tracking_reference,dispatched_at,delivered_at,recipient_name,version
) VALUES
('tenant-demo','12000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000001','delivered',
 CURRENT_TIMESTAMP - INTERVAL '22 days',CURRENT_TIMESTAMP - INTERVAL '20 days',CURRENT_TIMESTAMP - INTERVAL '6 days',
 'Demo Logistics','DEMO-TRACK-0001',CURRENT_TIMESTAMP - INTERVAL '6 days',CURRENT_TIMESTAMP - INTERVAL '5 days','Lena Schneider',4),
('tenant-demo','12000000-0000-4000-8000-000000000002','c0000000-0000-4000-8000-000000000002','scheduled',
 CURRENT_TIMESTAMP - INTERVAL '2 days',CURRENT_TIMESTAMP - INTERVAL '1 day',CURRENT_TIMESTAMP + INTERVAL '4 days',
 'Demo Logistics','DEMO-TRACK-0002',NULL,NULL,'Jonas Weber',2),
('tenant-demo','12000000-0000-4000-8000-000000000003','c0000000-0000-4000-8000-000000000003','awaiting_prerequisites',
 NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1)
ON CONFLICT (tenant_id,fulfillment_id) DO NOTHING;

-- ---------------------------------------------------------------------------
-- CRM applications, advisor tasks and interaction timeline
-- ---------------------------------------------------------------------------
INSERT INTO crm_applications(
 tenant_id,application_id,customer_id,offer_id,vehicle_id,quote_id,status,applicant_message,
 assigned_advisor_id,eligibility_status,eligibility_result,submitted_at,reviewed_at,created_by,updated_by
) VALUES
('tenant-demo','13000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','b0000000-0000-4000-8000-000000000001','40000000000000000000000000000004','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','converted','Bitte Lieferung vormittags einplanen.','advisor.berlin','eligible','{"score":"A","decision":"eligible","demo":true}',CURRENT_TIMESTAMP - INTERVAL '30 days',CURRENT_TIMESTAMP - INTERVAL '28 days','demo-fixtures','demo-fixtures'),
('tenant-demo','13000000-0000-4000-8000-000000000002','a0000000-0000-4000-8000-000000000002','b0000000-0000-4000-8000-000000000002','40000000000000000000000000000003','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','approved','Ich möchte das Fahrzeug zeitnah übernehmen.','advisor.koeln','eligible','{"score":"B","decision":"eligible","demo":true}',CURRENT_TIMESTAMP - INTERVAL '12 days',CURRENT_TIMESTAMP - INTERVAL '10 days','demo-fixtures','demo-fixtures'),
('tenant-demo','13000000-0000-4000-8000-000000000003','a0000000-0000-4000-8000-000000000003','b0000000-0000-4000-8000-000000000003','40000000000000000000000000000005','cccccccccccccccccccccccccccccccc','under_review','Bitte um Rückruf am Nachmittag.','advisor.muenchen','manual_review','{"score":"C","decision":"manual_review","demo":true}',CURRENT_TIMESTAMP - INTERVAL '7 days',NULL,'demo-fixtures','demo-fixtures'),
('tenant-demo','13000000-0000-4000-8000-000000000004','a0000000-0000-4000-8000-000000000005','b0000000-0000-4000-8000-000000000005','40000000000000000000000000000006','eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','declined','Bitte prüfen Sie die monatliche Rate.','advisor.duesseldorf','ineligible','{"score":"D","decision":"ineligible","demo":true}',CURRENT_TIMESTAMP - INTERVAL '20 days',CURRENT_TIMESTAMP - INTERVAL '18 days','demo-fixtures','demo-fixtures')
ON CONFLICT (tenant_id,application_id) DO NOTHING;

INSERT INTO crm_tasks(tenant_id,task_id,application_id,assigned_to,title,description,due_at,status,completed_at,created_by) VALUES
('tenant-demo','14000000-0000-4000-8000-000000000001','13000000-0000-4000-8000-000000000002','advisor.koeln','Identität prüfen','Demo-Aufgabe zur Prüfung der Kundendaten.',CURRENT_TIMESTAMP + INTERVAL '1 day','open',NULL,'demo-fixtures'),
('tenant-demo','14000000-0000-4000-8000-000000000002','13000000-0000-4000-8000-000000000003','advisor.muenchen','Rückruf vereinbaren','Kundenwunsch: Rückruf am Nachmittag.',CURRENT_TIMESTAMP + INTERVAL '4 hours','in_progress',NULL,'demo-fixtures'),
('tenant-demo','14000000-0000-4000-8000-000000000003','13000000-0000-4000-8000-000000000001','advisor.berlin','Lieferung abstimmen','Lieferfenster wurde bereits bestätigt.',CURRENT_TIMESTAMP - INTERVAL '8 days','completed',CURRENT_TIMESTAMP - INTERVAL '9 days','demo-fixtures')
ON CONFLICT (tenant_id,task_id) DO NOTHING;

INSERT INTO crm_interactions(tenant_id,interaction_id,application_id,actor_id,channel,subject,body,occurred_at) VALUES
('tenant-demo','15000000-0000-4000-8000-000000000001','13000000-0000-4000-8000-000000000001','advisor.berlin','phone','Beratung abgeschlossen','Konditionen und Lieferprozess erläutert.',CURRENT_TIMESTAMP - INTERVAL '27 days'),
('tenant-demo','15000000-0000-4000-8000-000000000002','13000000-0000-4000-8000-000000000002','advisor.koeln','email','Angebot versendet','Das Angebot wurde per E-Mail bereitgestellt.',CURRENT_TIMESTAMP - INTERVAL '9 days'),
('tenant-demo','15000000-0000-4000-8000-000000000003','13000000-0000-4000-8000-000000000003','advisor.muenchen','note','Manuelle Prüfung','Einkommensnachweis wird noch benötigt.',CURRENT_TIMESTAMP - INTERVAL '1 day')
ON CONFLICT (tenant_id,interaction_id) DO NOTHING;

COMMIT;
