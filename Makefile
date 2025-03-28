install:
	docker run --rm -v .:/data -w /data silintl/php8:8.3 composer install
