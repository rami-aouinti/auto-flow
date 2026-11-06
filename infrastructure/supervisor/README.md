# Process supervision

AutoFlow deliberately uses different process supervisors depending on the runtime.

## Docker Compose

The Offer worker container runs Supervisor as PID 1. The active configuration is
docker/php/supervisor/offer-workers.conf, copied into the service image by
docker/php/service.Dockerfile.

It supervises two independent PHP processes:
- Offer expiration polling worker.
- Offer Transactional Outbox publisher.

The processes have separate restart policies and write stdout/stderr to the container
log stream. Docker Compose then supervises the container itself with restart: unless-stopped.

This is a pragmatic local-development setup. For Kubernetes, do not nest Supervisor
inside Pods: use a Deployment for each long-running process and a CronJob for finite
scheduled tasks. That allows independent scaling, rollout and failure isolation.

## Traditional Linux VM

supervisord.conf.example is an optional example for a non-containerized VM deployment.
It shows the Offer and Vehicle Catalog publisher programs. Before using it:
- install Supervisor and deploy the release to /srv/autoflow;
- create an unprivileged autoflow user;
- inject database and RabbitMQ credentials through a protected environment mechanism;
- ensure /var/log/supervisor is writable and log rotation is configured;
- run supervisorctl reread and supervisorctl update;
- monitor supervisorctl status.

Offer expiration should be a finite scheduled task on Kubernetes (CronJob) or a VM
scheduler. Do not run the polling loop and the one-shot CronJob in the same environment.
