FROM php:8.1-apache

# 设置工作目录
WORKDIR /var/www/html
COPY . /var/www/html/

# 安装系统依赖
RUN apt-get update && apt-get install -y \
    libmagickwand-dev \
    libzip-dev \
    unzip \
    pkg-config \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# 安装 PHP 扩展
RUN docker-php-ext-install exif pcntl mysqli fileinfo \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# 设置 PHP 配置，屏蔽 Notice
RUN echo "display_errors = Off" >> /usr/local/etc/php/conf.d/docker-custom.ini \
    && echo "error_reporting = E_ALL & ~E_NOTICE" >> /usr/local/etc/php/conf.d/docker-custom.ini

# 开启 Apache Rewrite（如果需要）
RUN a2enmod rewrite

# 暴露端口
EXPOSE 80

# 默认启动 Apache
CMD ["apache2-foreground"]