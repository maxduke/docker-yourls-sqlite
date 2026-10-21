FROM yourls:latest

COPY ./yourls-sqlite/db.php /var/www/html/user/db.php

RUN mkdir -p /var/www/html/user/sqlite \
    && chown -R www-data:www-data /var/www/html/user

VOLUME ["/var/www/html/user/sqlite"]
