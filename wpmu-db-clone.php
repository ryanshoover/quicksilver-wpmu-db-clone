<?php
/**
 * Quicksilver action to update the sites
 */

use Symfony\Component\Process\Process;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Edit these domains to match your site's configuration.
 *
 * If your test or dev sites have a custom domain and support
 * wildcard subdomains, include them as well.
 */
$domains = [
	'live'    => 'mydomain.com',
	'lando'   => $site . '.lndo.site',
	'default' => $env . '-' . $site . '.pantheonsite.io',
];

/**
 * Run a command through Symfony's process
 *
 * @param string $cmd   The command to run.
 * @param bool   $async Whether to run the command sync or async.
 * @return Symfony\Component\Process\Process;
 */
function run_wp_cli( $cmd, $async = false ) {
	$cmd = array_merge( [ 'wp' ], (array) $cmd, [ '--skip-plugins', '--skip-themes', '--quiet' ] );
	$process = new Process( $cmd );
	$process->setTimeout( 60 * 10 );

	if ( $async ) {
		$process->start();
	} else {
		$process->mustRun();
	}

	return $process;
}

/**
 * Clean out done processes
 *
 * @param array $processes Array of Symfony processes.
 * @return array           Original processes, with complete ones filtered out.
 */
function clean_processes( $processes ) {
	foreach ( $processes as $key => $process ) {
		if ( $process->isTerminated() ) {
			if ( ! $process->isSuccessful() ) {
				echo $process->getErrorOutput();
			}

			unset( $processes[ $key ] );
		}
	}

	return $processes;
}

echo 'Replacing domain names in wp_blogs table' . PHP_EOL;

$env = getenv( 'PANTHEON_ENVIRONMENT' );
$site = getenv( 'PANTHEON_SITE_NAME' );

// If we don't have a pantheon environment, bail.
if ( empty( $env ) ) {
	echo "Missing environment information. Aborting.";
	return;
}

// If the database isn't coming from the live site, skip processing.
// We can't trust that we know what to set the blog path to if it's not the live database.
if ( 'live' === $env ) {
	echo "Database is being cloned to live environment." . PHP_EOL;
	echo "Manually update the wp_blogs and wp_site tables.";
	return;
}

// Figure out what the domain is for the current site.
$domain_new = $domains[ $env ] ?: $domains['default'];

// Should we move to a subdirectory setup?
// pantheonsite.io doesn't support subdomains, so we need to move to subdirectory
$is_subdirectory = stristr( $domain_new, 'pantheonsite.io' );

// Get the primary blog's domain.
$process     = run_wp_cli( [ 'db', 'query', 'SELECT domain FROM wp_blogs WHERE site_id=1 AND blog_id=1;', '--skip-column-names', "--url={$domains['live']}" ] );
$domain_orig = trim( $process->getOutput() );

// If the database isn't coming from the live site, skip processing.
// We can't trust that we know what to set the blog path to if it's not the live database.
if ( $domains['live'] !== $domain_orig ) {
	echo "Origin database isn't from live, skipping table processing.";
	return;
}

// Get the list of sites.
$process = run_wp_cli( [ 'db', 'query', 'SELECT blog_id, domain, path FROM wp_blogs WHERE site_id=1', '--skip-column-names', "--url={$domains['live']}" ] );
$blogs   = explode( PHP_EOL, $process->getOutput() );

// Update wp_site domain to the new domain.
run_wp_cli( [ 'db', 'query', "UPDATE wp_site SET domain='{$domain_new}', path='/' WHERE id=1", "--url={$domains['live']}" ], true );

$processes = [];

// Update individual site urls.
foreach ( $blogs as $blog_raw ) {
	$blog    = explode( "\t", $blog_raw );
	$blog_id = intval( $blog[0] );

	// If the blog ID isn't a positive integer, something's not right. Skip it.
	if ( 0 >= $blog_id ) {
		continue;
	}

	$blog_domain_orig = $blog[1];
	$blog_path_orig   = $blog[2];

	echo "Processing site #$blog_id {$blog_domain_orig}{$blog_path_orig}\n";

	if ( $is_subdirectory ) {
		// Convert URLs to a subdirectory pattern.
		// site.com           => test-site.pantheonsite.io
		// blog.site.com      => test-site.pantheonsite.io/blog/
		// blog.site.com/dir/ => test-site.pantheonsite.io/blog-dir/
		// blog.com           => test-site.pantheonsite.io/blog-com/
		// blog.com/dir/      => test-site.pantheonsite.io/blog-com-dir/

		// Process URLs to a subdirectory format.
		$blog_domain_new = $domain_new;

		if ( 1 == $blog_id ) {
			// First blog gets a path of just /
			$blog_path_new = '/';
		} else {
			// All other blogs get a path made of the subdomain and original path.
			$blog_path_new = str_replace( '.' . $domain_orig, '', $blog_domain_orig ) . $blog_path_orig;

			// Convert to a single subdirectory.
			$blog_path_new = '/' . str_replace( ['.', '/' ], '-', $blog_path_new );

			$blog_path_new = rtrim( $blog_path_new, '-' ) . '/';
		}
	} else {
		// Process URLs to a subdomain format.
		$blog_path_new = $blog_path_orig;

		if ( 1 === $blog_id ) {
			$blog_domain_new = $domain_new;
		} else {
			// First, remove the live domain from the site's original domain
			$subdir = str_replace( ".{$domains['live']}", '', $blog_domain_orig );
			// For edge cases of sub-sub domains or fully unique domains, swap dots to dashes.
			$subdir = str_replace( '.', '-', $subdir );

			$blog_domain_new = "{$subdir}.{$domain_new}";
		}
	}

	// Update wp_blogs record.
	run_wp_cli( [ 'db', 'query', "UPDATE wp_blogs SET domain='{$blog_domain_new}', path='{$blog_path_new}' WHERE site_id=1 AND blog_id={$blog_id}", "--url={$domains['live']}" ], false );

	// Run search-replace on all of the blog's tables.
	// Search-replace limited to just the blog's tables for speed.
	$blog_url_orig = trim( "{$blog_domain_orig}{$blog_path_orig}", '/' );
	$blog_url_new  = trim( "{$blog_domain_new}{$blog_path_new}", '/' );
	$processes[] = run_wp_cli( [ 'search-replace', "//$blog_url_orig", "//$blog_url_new", "--url=$blog_url_new", '--skip-tables=wp_blogs,wp_site' ], true );

	while ( count ( $processes ) > 100 ) {
		$processes = clean_processes( $processes );
		sleep( 1 );
	}
}

// Wait for all processes to finish.
while ( ! empty( $processes ) ) {
	$processes = clean_processes( $processes );

	sleep( 1 );

	printf( '%d processes executing', count( $processes ) );
	echo PHP_EOL;
}
