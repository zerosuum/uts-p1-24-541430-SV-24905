
#!/bin/sh
set -eu

php artisan migrate --force

if [ "${SEED_DEMO_USER:-false}" = "true" ]; then
    php artisan db:seed --class=DemoUserSeeder --force
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile
EOF

chmod +x backend/docker-start.sh
sh -n backend/docker-start.sh