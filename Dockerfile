
# Pin YOURLS version to avoid unexpected upgrades
FROM yourls:1.10.6-apache

# Include SQLite driver in the official source directory
COPY --chown=www-data:www-data \
    ./yourls-sqlite/db.php \
    /usr/src/yourls/user/db.php

# Preserve compatibility with existing container layout
COPY --chown=www-data:www-data \
    ./yourls-sqlite/db.php \
    /var/www/html/user/db.php

# Validate driver and required PHP extension
RUN php -l /usr/src/yourls/user/db.php \
    && php -r 'exit(in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);' \
    && mkdir -p /var/www/html/user/sqlite \
    && chown -R www-data:www-data /var/www/html/user

VOLUME ["/var/www/html/user/sqlite"]

EXPOSE 8080
