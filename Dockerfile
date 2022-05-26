FROM yourls:latest

COPY ./yourls-sqlite/db.php /var/www/html/user/db.php

RUN mkdir /var/www/html/user/sqlite
VOLUME ["/var/www/html/user/sqlite"]

ENTRYPOINT ["docker-entrypoint.sh"]

CMD ["apache2-foreground"]
