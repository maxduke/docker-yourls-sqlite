FROM yourls:1.10.6-apache

# Source copy for the official entrypoint, live copy for its pre-existing layout.
COPY --chown=www-data:www-data yourls-sqlite/ /usr/src/yourls/user/
COPY --chown=www-data:www-data yourls-sqlite/ /var/www/html/user/
COPY scripts/migrate-506-to-507.php /usr/local/bin/migrate-506-to-507.php

RUN php -l /usr/src/yourls/user/db.php \
    && php -r 'exit(in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);' \
    && mkdir -p /var/www/html/user/sqlite \
    && chown www-data:www-data /var/www/html/user/sqlite

VOLUME ["/var/www/html/user/sqlite"]
EXPOSE 8080
