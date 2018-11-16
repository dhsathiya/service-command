<?php

namespace EE\Service\Utils;

use EE;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots up the container if it is stopped or not running.
 * @throws EE\ExitException
 */
function nginx_proxy_check() {

	$proxy_type = EE_PROXY_TYPE;

	$config_80_port  = \EE\Utils\get_config_value( 'proxy_80_port', '80' );
	$config_443_port = \EE\Utils\get_config_value( 'proxy_443_port', '443' );

	if ( 'running' === EE::docker()::container_status( $proxy_type ) ) {
		$launch_80_test  = EE::launch( 'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "80/tcp") 0).HostPort }}\' ee-global-nginx-proxy' );
		$launch_443_test = EE::launch( 'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "443/tcp") 0).HostPort }}\' ee-global-nginx-proxy' );

		if ( $config_80_port !== trim( $launch_80_test->stdout ) || $config_443_port !== trim( $launch_443_test->stdout ) ) {
			EE::error( "Ports of current running nginx-proxy and ports specified in EasyEngine config file don't match." );
		}

		return;
	}

	/**
	 * Checking ports.
	 */
	$port_80_status  = \EE\Utils\get_curl_info( 'localhost', $config_80_port, true );
	$port_443_status = \EE\Utils\get_curl_info( 'localhost', $config_443_port, true );

	// if any/both the port/s is/are occupied.
	if ( ! ( $port_80_status && $port_443_status ) ) {
		EE::error( "Cannot create/start proxy container. Please make sure port $config_80_port and $config_443_port are free." );
	} else {

		$fs = new Filesystem();

		if ( ! $fs->exists( EE_ROOT_DIR . '/services/docker-compose.yml' ) ) {
			generate_global_docker_compose_yml( $fs );
		}

		boot_global_networks();
		if ( ! EE::docker()::docker_compose_up( EE_ROOT_DIR . '/services', [ 'global-nginx-proxy' ] ) ) {
			EE::error( "There was some error in starting $proxy_type container. Please check logs." );
		}
	}
}

/**
 * Function to start global conainer if it is not running.
 *
 * @param string $container Global container to be brought up.
 */
function init_global_container( $service, $container = '' ) {

	if ( empty( $container ) ) {
		$container = 'ee-' . $service;
	}

	boot_global_networks();

	$fs = new Filesystem();

	if ( ! $fs->exists( EE_ROOT_DIR . '/services/docker-compose.yml' ) ) {
		generate_global_docker_compose_yml( $fs );
	}

	if ( 'running' !== EE::docker()::container_status( $container ) ) {
		chdir( EE_ROOT_DIR . '/services' );
		$db_conf_file = EE_ROOT_DIR . '/services/mariadb/conf/my.cnf';
		if ( IS_DARWIN && GLOBAL_DB === $service && ! $fs->exists( $db_conf_file ) ) {
			$fs->copy( SERVICE_TEMPLATE_ROOT . '/my.cnf.mustache', $db_conf_file );
		}
		EE::docker()::boot_container( $container, 'docker-compose up -d ' . $service );
	} else {
		EE::log( "$service: Service already running" );

		return;
	}

	EE::success( "$container container is up" );

}

/**
 * Start required global networks if they don't exist.
 */
function boot_global_networks() {
	if ( ! EE::docker()::docker_network_exists( GLOBAL_BACKEND_NETWORK ) &&
	     ! EE::docker()::create_network( GLOBAL_BACKEND_NETWORK ) ) {
		EE::error( 'Unable to create network ' . GLOBAL_BACKEND_NETWORK );
	}
	if ( ! EE::docker()::docker_network_exists( GLOBAL_FRONTEND_NETWORK ) &&
	     ! EE::docker()::create_network( GLOBAL_FRONTEND_NETWORK ) ) {
		EE::error( 'Unable to create network ' . GLOBAL_FRONTEND_NETWORK );
	}
}

/**
 * Generates global docker-compose.yml at EE_ROOT_DIR
 *
 * @param Filesystem $fs Filesystem object to write file.
 */
function generate_global_docker_compose_yml( Filesystem $fs ) {

	$img_versions    = EE\Utils\get_image_versions();
	$config_80_port  = \EE\Utils\get_config_value( 'proxy_80_port', 80 );
	$config_443_port = \EE\Utils\get_config_value( 'proxy_443_port', 443 );

	$volumes_nginx_proxy = [
		[
			'name'            => 'certs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/certs',
			'container_path'  => '/etc/nginx/certs',
		],
		[
			'name'            => 'dhparam',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/dhparam',
			'container_path'  => '/etc/nginx/dhparam',
		],
		[
			'name'            => 'confd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/conf.d',
			'container_path'  => '/etc/nginx/conf.d',
		],
		[
			'name'            => 'htpasswd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/htpasswd',
			'container_path'  => '/etc/nginx/htpasswd',
		],
		[
			'name'            => 'vhostd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/vhost.d',
			'container_path'  => '/etc/nginx/vhost.d',
		],
		[
			'name'            => 'html',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/html',
			'container_path'  => '/usr/share/nginx/html',
		],
		[
			'name'            => 'nginx_proxy_logs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/logs',
			'container_path'  => '/var/log/nginx',
		],
		[
			'name'            => 'nginx_proxy_logs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/logs',
			'container_path'  => '/var/log/nginx',
		],
		[
			'name'            => '/var/run/docker.sock',
			'path_to_symlink' => '/var/run/docker.sock',
			'container_path'  => '/tmp/docker.sock:ro',
			'skip_volume'     => true,
		],
	];

	$volumes_db    = [
		[
			'name'            => 'db_data',
			'path_to_symlink' => EE_ROOT_DIR . '/services/mariadb/data',
			'container_path'  => '/var/lib/mysql',
		],
		[
			'name'            => 'db_conf',
			'path_to_symlink' => EE_ROOT_DIR . '/services/mariadb/conf',
			'container_path'  => '/etc/mysql',
			'skip_darwin'     => true,
		],
		[
			'name'            => 'db_conf',
			'path_to_symlink' => EE_ROOT_DIR . '/services/mariadb/conf/my.cnf',
			'container_path'  => '/etc/mysql/my.cnf',
			'skip_linux'      => true,
			'skip_volume'     => true,
		],
		[
			'name'            => 'db_logs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/mariadb/logs',
			'container_path'  => '/var/log/mysql',
		],
	];
	$volumes_redis = [
		[
			'name'            => 'redis_data',
			'path_to_symlink' => EE_ROOT_DIR . '/services/redis/data',
			'container_path'  => '/data',
			'skip_darwin'     => true,
		],
		[
			'name'            => 'redis_conf',
			'path_to_symlink' => EE_ROOT_DIR . '/services/redis/conf',
			'container_path'  => '/usr/local/etc/redis',
			'skip_darwin'     => true,
		],
		[
			'name'            => 'redis_logs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/redis/logs',
			'container_path'  => '/var/log/redis',
		],
	];

	if ( ! IS_DARWIN ) {

		$data['created_volumes'] = [
			'external_vols' => [
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'certs' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'dhparam' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'confd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'htpasswd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'vhostd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'html' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'nginx_proxy_logs' ],
				[ 'prefix' => GLOBAL_DB, 'ext_vol_name' => 'db_data' ],
				[ 'prefix' => GLOBAL_DB, 'ext_vol_name' => 'db_conf' ],
				[ 'prefix' => GLOBAL_DB, 'ext_vol_name' => 'db_logs' ],
				[ 'prefix' => GLOBAL_REDIS, 'ext_vol_name' => 'redis_data' ],
				[ 'prefix' => GLOBAL_REDIS, 'ext_vol_name' => 'redis_conf' ],
				[ 'prefix' => GLOBAL_REDIS, 'ext_vol_name' => 'redis_logs' ],
			],
		];

		if ( empty( EE::docker()::get_volumes_by_label( 'global-nginx-proxy' ) ) ) {
			EE::docker()::create_volumes( 'global-nginx-proxy', $volumes_nginx_proxy, false );
		}

		if ( empty( EE::docker()::get_volumes_by_label( GLOBAL_DB ) ) ) {
			EE::docker()::create_volumes( GLOBAL_DB, $volumes_db, false );
		}

		if ( empty( EE::docker()::get_volumes_by_label( GLOBAL_REDIS ) ) ) {
			EE::docker()::create_volumes( GLOBAL_REDIS, $volumes_redis, false );
		}
	}

	$data['services'] = [
		[
			'name'           => 'global-nginx-proxy',
			'container_name' => EE_PROXY_TYPE,
			'image'          => 'easyengine/nginx-proxy:' . $img_versions['easyengine/nginx-proxy'],
			'restart'        => 'always',
			'ports'          => [
				"$config_80_port:80",
				"$config_443_port:443",
			],
			'environment'    => [
				'LOCAL_USER_ID=' . posix_geteuid(),
				'LOCAL_GROUP_ID=' . posix_getegid(),
			],
			'volumes'        => \EE_DOCKER::get_mounting_volume_array( $volumes_nginx_proxy ),
			'networks'       => [
				'global-frontend-network',
			],
		],
		[
			'name'           => GLOBAL_DB,
			'container_name' => GLOBAL_DB_CONTAINER,
			'image'          => 'easyengine/mariadb:' . $img_versions['easyengine/mariadb'],
			'restart'        => 'always',
			'environment'    => [
				'MYSQL_ROOT_PASSWORD=' . \EE\Utils\random_password(),
			],
			'volumes'        => \EE_DOCKER::get_mounting_volume_array( $volumes_db ),
			'networks'       => [
				'global-backend-network',
			],
		],
		[
			'name'           => GLOBAL_REDIS,
			'container_name' => GLOBAL_REDIS_CONTAINER,
			'image'          => 'easyengine/redis:' . $img_versions['easyengine/redis'],
			'restart'        => 'always',
			'command'        => '["redis-server", "/usr/local/etc/redis/redis.conf"]',
			'volumes'        => \EE_DOCKER::get_mounting_volume_array( $volumes_redis ),
			'networks'       => [
				'global-backend-network',
			],
		],
	];

	$contents = EE\Utils\mustache_render( SERVICE_TEMPLATE_ROOT . '/global_docker_compose.yml.mustache', $data );
	$fs->dumpFile( EE_ROOT_DIR . '/services/docker-compose.yml', $contents );
}
