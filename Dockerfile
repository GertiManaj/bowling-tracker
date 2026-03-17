FROM php:8.2-cli

RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . .

RUN chmod +x start.sh
CMD ["bash", "start.sh"]
