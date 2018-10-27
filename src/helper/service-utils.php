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
	if ( 'running' !== EE::docker()::container_status( $proxy_type ) ) {
		/**
		 * Checking ports.
		 */
		$port_80_status  = \EE\Utils\get_curl_info( 'localhost', 80, true );
		$port_443_status = \EE\Utils\get_curl_info( 'localhost', 443, true );

		// if any/both the port/s is/are occupied.
		if ( ! ( $port_80_status && $port_443_status ) ) {
			EE::error( 'Cannot create/start proxy container. Please make sure port 80 and 443 are free.' );
		} else {

			$fs = new Filesystem();

			create_global_volumes();

			if ( ! $fs->exists( EE_ROOT_DIR . '/services/docker-compose.yml' ) ) {
				generate_global_docker_compose_yml( $fs );
			}

			$EE_ROOT_DIR = EE_ROOT_DIR;
			boot_global_networks();
			if ( EE::docker()::docker_compose_up( EE_ROOT_DIR . '/services', [ 'global-nginx-proxy' ] ) ) {
				$fs->dumpFile( "$EE_ROOT_DIR/services/nginx-proxy/conf.d/custom.conf", file_get_contents( EE_ROOT . '/templates/custom.conf.mustache' ) );
				EE::success( "$proxy_type container is up." );
			} else {
				EE::error( "There was some error in starting $proxy_type container. Please check logs." );
			}
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

		if ( empty( EE::docker()::get_volumes_by_label( $service ) ) ) {
			create_global_volumes();
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
 * Function to create all necessary volumes for global containers.
 */
function create_global_volumes() {

	$volumes = [
		[
			'name'            => 'certs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/certs',
		],
		[
			'name'            => 'dhparam',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/dhparam',
		],
		[
			'name'            => 'confd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/conf.d',
		],
		[
			'name'            => 'htpasswd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/htpasswd',
		],
		[
			'name'            => 'vhostd',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/vhost.d',
		],
		[
			'name'            => 'html',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/html',
		],
		[
			'name'            => 'nginx_proxy_logs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/nginx-proxy/logs',
		],
	];

	$volumes_db    = [
		[
			'name'            => 'db_data',
			'path_to_symlink' => EE_ROOT_DIR . '/services/mariadb/data',
		],
		[
			'name'            => 'db_conf',
			'path_to_symlink' => EE_ROOT_DIR . '/services/mariadb/conf',
		],
		[
			'name'            => 'db_logs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/mariadb/logs',
		],
	];
	$volumes_redis = [
		[
			'name'            => 'redis_data',
			'path_to_symlink' => EE_ROOT_DIR . '/services/redis/data',
		],
		[
			'name'            => 'redis_conf',
			'path_to_symlink' => EE_ROOT_DIR . '/services/redis/conf',
		],
		[
			'name'            => 'redis_logs',
			'path_to_symlink' => EE_ROOT_DIR . '/services/redis/logs',
		],
	];

	if ( empty( EE::docker()::get_volumes_by_label( 'global-nginx-proxy' ) ) ) {
		EE::docker()::create_volumes( 'global-nginx-proxy', $volumes, false );
	}

	if ( empty( EE::docker()::get_volumes_by_label( GLOBAL_DB ) ) ) {
		EE::docker()::create_volumes( GLOBAL_DB, $volumes_db, false );
	}

	if ( empty( EE::docker()::get_volumes_by_label( GLOBAL_REDIS ) ) ) {
		EE::docker()::create_volumes( GLOBAL_REDIS, $volumes_redis, false );
	}
}

/**
 * Generates global docker-compose.yml at EE_ROOT_DIR
 *
 * @param Filesystem $fs Filesystem object to write file.
 */
function generate_global_docker_compose_yml( Filesystem $fs ) {
	$img_versions = EE\Utils\get_image_versions();

	$data = [
		'services'        => [
			[
				'name'           => 'global-nginx-proxy',
				'container_name' => EE_PROXY_TYPE,
				'image'          => 'easyengine/nginx-proxy:' . $img_versions['easyengine/nginx-proxy'],
				'restart'        => 'always',
				'ports'          => [
					'80:80',
					'443:443',
				],
				'environment'    => [
					'LOCAL_USER_ID=' . posix_geteuid(),
					'LOCAL_GROUP_ID=' . posix_getegid(),
				],
				'volumes'        => [
					'certs:/etc/nginx/certs',
					'dhparam:/etc/nginx/dhparam',
					'confd:/etc/nginx/conf.d',
					'htpasswd:/etc/nginx/htpasswd',
					'vhostd:/etc/nginx/vhost.d',
					'html:/usr/share/nginx/html',
					'nginx_proxy_logs:/var/log/nginx',
					'/var/run/docker.sock:/tmp/docker.sock:ro',
				],
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
				'volumes'        => [
					'db_data:/var/lib/mysql',
					'db_conf:/etc/mysql',
					'db_logs:/var/log/mysql',
				],
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
				'volumes'        => [
					'redis_data:/data',
					'redis_conf:/usr/local/etc/redis',
					'redis_logs:/var/log/redis',
				],
				'networks'       => [
					'global-backend-network',
				],
			],
		],
		'created_volumes' => [
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
		],
	];

	$contents = EE\Utils\mustache_render( SERVICE_TEMPLATE_ROOT . '/global_docker_compose.yml.mustache', $data );
	$fs->dumpFile( EE_ROOT_DIR . '/services/docker-compose.yml', $contents );
}
