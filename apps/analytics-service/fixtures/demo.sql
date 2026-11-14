INSERT INTO analytics_service.events(tenant_id,event_id,event_type,occurred_at,payload) VALUES
('tenant-demo','demo:offer:created:001','offer.created',now()-interval '5 days','{"offerId":"demo-offer-001"}'),
('tenant-demo','demo:offer:published:001','offer.published',now()-interval '4 days','{"offerId":"demo-offer-001"}'),
('tenant-demo','demo:offer:accepted:001','offer.accepted',now()-interval '2 days','{"offerId":"demo-offer-001"}'),
('tenant-demo','demo:payment:succeeded:001','payment.succeeded',now()-interval '1 day','{"paymentId":"demo-payment-001","amount":455.46,"currency":"EUR"}'),
('tenant-demo','demo:delivery:completed:001','fulfillment.completed',now(),'{"orderId":"demo-order-001"}')
ON CONFLICT (tenant_id,event_id) DO NOTHING;
