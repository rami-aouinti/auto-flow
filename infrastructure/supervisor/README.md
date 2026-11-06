# Process supervision

AutoFlow uses one long-running process per container. Docker Compose supervises the
container with its restart policy. Kubernetes supervises API and publisher processes
through Deployments; finite scheduled work belongs in CronJobs.

Do not run Supervisor inside these application containers as a second orchestrator.
The optional configuration in supervisord.conf.example is for a traditional Linux VM
deployment where Supervisor is the process manager.

Before using it on a VM:
- install Supervisor and deploy the release to /srv/autoflow;
- create an unprivileged autoflow user;
- inject database and RabbitMQ credentials through a protected environment mechanism;
- ensure /var/log/supervisor is writable and log rotation is configured;
- run supervisorctl reread and supervisorctl update;
- monitor supervisorctl status.

Offer expiration is intentionally not a permanent Supervisor process in the target
architecture. It is a finite scheduled job, so Kubernetes CronJob or an external
scheduler is a better fit.
