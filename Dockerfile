FROM php:8.2-cli

RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . .

CMD ["sh", "-c", "echo PORT=$PORT && php -S 0.0.0.0:${PORT:-8080} router.php"]
