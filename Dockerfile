FROM yourls:1.9-fpm-alpine

COPY ./yourls-sqlite/db.php /var/www/html/user/db.php

RUN mkdir /var/www/html/user/sqlite
VOLUME ["/var/www/html/user/sqlite"]

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
