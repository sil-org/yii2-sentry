install:
	docker run --rm -v .:/data -w /data ghcr.io/sil-org/php8:8.3 composer install
