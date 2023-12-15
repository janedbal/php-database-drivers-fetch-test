
echo ">> Running PHP 7.2:" && docker-compose run --rm php72 composer update > /dev/null && docker-compose run --rm php72 php vendor/bin/phpunit tests
echo ">> Running PHP 7.3:" && docker-compose run --rm php73 composer update > /dev/null && docker-compose run --rm php73 php vendor/bin/phpunit tests
echo ">> Running PHP 7.4:" && docker-compose run --rm php74 composer update > /dev/null && docker-compose run --rm php74 php vendor/bin/phpunit tests
echo ">> Running PHP 8.0:" && docker-compose run --rm php80 composer update > /dev/null && docker-compose run --rm php80 php vendor/bin/phpunit tests
echo ">> Running PHP 8.1:" && docker-compose run --rm php81 composer update > /dev/null && docker-compose run --rm php81 php vendor/bin/phpunit tests
echo ">> Running PHP 8.2:" && docker-compose run --rm php82 composer update > /dev/null && docker-compose run --rm php82 php vendor/bin/phpunit tests
echo ">> Running PHP 8.3:" && docker-compose run --rm php83 composer update > /dev/null && docker-compose run --rm php83 php vendor/bin/phpunit tests
