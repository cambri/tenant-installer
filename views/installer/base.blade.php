#!/usr/bin/env bash

#######################################################################
#
#   Formatting funkiness favorable fathomness
#
#######################################################################

# colors to be used for displaying messages while running the script
COLOR_DEFAULT='\033[0m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_GREEN='\033[0;32m'
COLOR_LIGHT_GRAY='\033[0;37m'

function writeLn ()
{
    local line="$1"
    local color="$2"
    local appendNewLines="$3"

    color=${color:-${COLOR_DEFAULT}}
    appendNewLines=${appendNewLines:-1}

    printf "${color}${line}${COLOR_DEFAULT}"

    while [ ${appendNewLines} -gt 0 ]
    do
        printf "\n"
        appendNewLines=$[$appendNewLines-1]
    done
}
function writeQuestion ()
{
    writeLn "$1" ${COLOR_YELLOW}
}
function writeError ()
{
    writeLn "$1" ${COLOR_RED}
}
function writeInfo ()
{
    writeLn "$1" ${COLOR_LIGHT_GRAY}
}
function writeHeader ()
{
    writeLn "$1" ${COLOR_GREEN}
}
function writeDebug ()
{
    if [ ${DEBUG} -eq 1 ]; then
        writeInfo "DEBUG: $1"
    fi
}

#########################################################
#
#   Variables and definitions
#
#########################################################

php_user="www-data"
bash_user=${USER}

start_directory=${PWD}

database_username="hyn"
database_name="hyn_multi_tenancy"
database_password=""

app_key=""

# this might become invalidated because it's a token generated per account
github_token="b407c2429ec1a63d7a338bc7706cb408cb593f37"

#########################################################
#
#   Install requirements
#
#########################################################

sudo apt-get update -y

# Webserver, PHP, Redis, Mariadb, OpenSSL, CURL
sudo apt-get install -y -f apache2 libapache2-mod-fcgid libapache2-mod-php5 php5 php5-mysql php5-mcrypt php5-json php5-fpm php5-cli redis-server mariadb-server openssl curl pwgen supervisor beanstalkd

# apache
a2enmod rewrite
a2enmod headers
a2enmod actions

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

composer config -g github-oauth.github.com ${github_token}

#########################################################
#
#   Create database
#
#########################################################

database_password=`pwgen -cnsB 15 1`
app_key=`pwgen -cnsB 32 1`

sudo mysql -e "create database ${database_name};"
sudo mysql -e "create user ${database_username}@localhost identified by '${database_password}';"
sudo mysql -e "grant all privileges on *.* to ${database_username}@localhost with grant option;"

#########################################################
#
#   Install source
#
#########################################################

cd /var/www

sudo rm -rf html

composer create-project laravel/laravel . --no-dev --prefer-dist 5.1
composer require predis/predis --update-no-dev --prefer-dist
composer require pda/pheanstalk --update-no-dev --prefer-dist
composer require hyn/multi-tenant --update-no-dev --prefer-dist
@if(isset($interface))
    composer require hyn/management-interface --update-no-dev --prefer-dist
@endif

mkdir log
ln -s public ./html

#########################################################
#
#   Configure .env
#
#########################################################
(
    echo "APP_ENV=production"
    echo "APP_DEBUG=false"
    echo "DB_HOST=localhost"
    echo "DB_DATABASE=${database_name}"
    echo "DB_USERNAME=${database_username}"
    echo "DB_PASSWORD=${database_password}"

    echo "CACHE_DRIVER=redis"
    echo "SESSION_DRIVER=redis"
    echo "QUEUE_DRIVER=beanstalkd"
) > .env

#         App\Providers\RouteServiceProvider::class,

#########################################################
#
#   Register multi tenancy
#
#########################################################


php -r "require_once 'vendor/autoload.php'; \$appConfig = include 'config/app.php'; \$appConfig['key'] = '${app_key}'; \$appConfig['providers'][] = Hyn\Framework\FrameworkServiceProvider::class; file_put_contents('config/app.php', sprintf('<?php return %s;', var_export(\$appConfig, true)));"
php -r "\$config = include 'vendor/hyn/multi-tenant/config/multi-tenant.php'; \$config['db']['system-connection-name'] = 'mysql'; file_put_contents('config/multi-tenant.php', sprintf('<?php return %s;', var_export(\$config, true)));"

#########################################################
#
#   Run setup
#
#########################################################

writeQuestion "Specify the first (admin) tenant (company) name:"
read tenant
writeQuestion "Specify the email address of this tenant:"
read email
writeQuestion "Specify the first hostname, it must resolve to this server:"
read hostname

php artisan multi-tenant:setup --tenant="${tenant}" --email="${email}" --hostname="${hostname}" --webserver=apache

#########################################################
#
#   Configure queue/supervisor
#
#########################################################
(
    echo "[program:laravel-worker]"
    echo "process_name=%(program_name)s_%(process_num)02d"
    echo "command=php ${PWD}/artisan queue:work beanstalkd --sleep=3 --tries=3 --daemon"
    echo "autostart=true"
    echo "autorestart=true"
    echo "user=${php_user}"
    echo "numprocs=4"
    echo "redirect_stderr=true"
    echo "stdout_logfile=${PWD}/log/worker.log"
) > supervisord.conf
sudo mv supervisord.conf /etc/supervisor/conf.d/laravel.conf
sudo supervisorctl update

sudo chown --recursive ${php_user} ${PWD}
