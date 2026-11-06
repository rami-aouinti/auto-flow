package orders

import (
	"net/url"
	"os"
)

func rabbitURL() string {
	host := env("RABBITMQ_HOST", "rabbitmq")
	port := env("RABBITMQ_PORT", "5672")
	vhost := env("RABBITMQ_VHOST", "/")
	if custom := os.Getenv("RABBITMQ_URL"); custom != "" {
		return custom
	}
	broker := url.URL{
		Scheme: "amqp",
		User: url.UserPassword(env("RABBITMQ_USER", "autoflow"), env("RABBITMQ_PASSWORD", "autoflow")),
		Host: host + ":" + port,
		Path: "/" + vhost,
	}
	return broker.String()
}
