FROM php:8.1-apache

# 设置工作目录
WORKDIR /var/www/html
COPY . /var/www/html/

# 安装系统依赖
RUN apt-get update && apt-get install -y \
    libmagickwand-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# 安装 PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_sqlite exif fileinfo gd \
    && pecl install imagick \
    && docker-php-ext-enable imagick

# 设置 PHP 配置，屏蔽 Notice
RUN echo "display_errors = Off" >> /usr/local/etc/php/conf.d/docker-custom.ini \
    && echo "error_reporting = E_ALL & ~E_NOTICE" >> /usr/local/etc/php/conf.d/docker-custom.ini

# 设置权限
RUN chown -R www-data:www-data /var/www/html

# 配置 Apache
RUN a2enmod rewrite

# 暴露端口
EXPOSE 80

# 默认启动 Apache
CMD ["apache2-foreground"]
