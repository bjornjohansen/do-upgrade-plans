<?php

define( 'DO_ACCESS_TOKEN', getenv( 'DO_ACCESS_TOKEN' ) );

define( 'DO_API_URL_BASE', 'https://api.digitalocean.com/v2/' );

if ( ! strlen( DO_ACCESS_TOKEN ) ) {
	fwrite( STDERR, "Please set your DigitalOcean API access token first.\n" );
	exit( 1 );
}

require( __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' );

use Httpful\Request;

$done_droplets = $droplet_errors = $regions = $sizes = $droplets = $shutdown_droplets = $migrate_droplets = $boot_droplets = [];

// Fetch all sizes.
$url = DO_API_URL_BASE . 'sizes';
do {
	echo sprintf( "Fetching URL: %s\n", $url );
	$sizes_response = Request::get( $url )->expectsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->send();
	$sizes = array_merge( $sizes, $sizes_response->body->sizes );
	$url = isset( $sizes_response->body->links->pages->next ) ? $sizes_response->body->links->pages->next : false;
} while ( $url );

echo sprintf( "We have found %d plans.\n", count( $sizes ) );

// Fetch all regions, because the regions registered per plan is limited.
$url = DO_API_URL_BASE . 'regions';
do {
	echo sprintf( "Fetching URL: %s\n", $url );
	$regions_response = Request::get( $url )->expectsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->send();
	$regions = array_merge( $regions, $regions_response->body->regions );
	$url = isset( $regions_response->body->links->pages->next ) ? $regions_response->body->links->pages->next : false;
} while ( $url );

echo sprintf( "We have found %d regions.\n", count( $regions ) );

// Loop through all regions, find the plans available and register the region into the plan if not there already.
foreach ( $regions as $region ) {
	foreach ( $region->sizes as $region_size ) {
		foreach ( $sizes as $key => $size ) {
			if ( ! in_array( $region->slug, $size->regions ) ) {
				echo sprintf( "Adding the region %s to the size %s.\n", $region->slug, $size->slug );
				$sizes[ $key ]->regions[] = $region->slug;
			}
		}
	}
}

// Fetch all droplets.
$url = DO_API_URL_BASE . 'droplets';
do {
	echo sprintf( "Fetching URL: %s\n", $url );
	$droplets_response = Request::get( $url )->expectsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->send();
	$droplets = array_merge( $droplets, $droplets_response->body->droplets );
	$url = isset( $droplets_response->body->links->pages->next ) ? $droplets_response->body->links->pages->next : false;
} while ( $url );

echo sprintf( "We have found %d droplets.\n", count( $droplets ) );

foreach ( $droplets as $droplet ) {

	// Can this droplet be upgraded to a plan with better specs in the same region?
	echo sprintf( "Checking if we can upgrade %s (%s - %s) in %s ...", $droplet->name, $droplet->id, $droplet->size->slug, $droplet->region->slug );
	$upgrade_memory = $droplet->size->memory;
	$upgrade_vcpus = $droplet->size->vcpus;
	$same_price_plans = [];
	$new_plan = false;
	foreach ( $sizes as $plan ) {
		if ( ! $plan->available || ! in_array( $droplet->region->slug, $plan->regions ) ) {
			continue;
		}
		if ( $plan->price_hourly === $droplet->size->price_hourly ) {

			if ( $plan->memory > $upgrade_memory && $plan->vcpus > $upgrade_vcpus ) {
				// Both RAM and VCPUs are better – than what we’ve found so far.
				$new_plan = $plan->slug;
				echo sprintf( '%s RAM is more than %s RAM and %s vCPUs is more than %s vCPUs. ', $plan->memory, $upgrade_memory, $plan->vcpus, $upgrade_vcpus );
			} elseif ( $plan->memory > $upgrade_memory && $plan->vcpus >= $upgrade_vcpus) {
				// RAM is better and VCPUs is at least the same – than what we’ve found so far.
				$new_plan = $plan->slug;
				echo sprintf( '%s RAM is more than %s RAM and %s vCPUs is more or the same as %s vCPUs. ', $plan->memory, $upgrade_memory, $plan->vcpus, $upgrade_vcpus );
			} elseif ( $plan->memory >= $upgrade_memory && $plan->vcpus > $upgrade_vcpus) {
				// RAM is at least the same as what we’ve found so far, but VCPUs are better.
				$new_plan = $plan->slug;
				echo sprintf( '%s RAM is more or the same as %s RAM and %s vCPUs is more than %s vCPUs. ', $plan->memory, $upgrade_memory, $plan->vcpus, $upgrade_vcpus );
			}
		}
	}


	if ( $new_plan ) {

		echo sprintf( "Yeah, let’s upgrade it to %s.", $new_plan );

		$d = new stdClass();
		$d->id = $droplet->id;
		$d->name = $droplet->name;
		$d->new_plan = $new_plan;

		// Add the droplet to the shutdown stack.
		$shutdown_droplets[] = $d;
			
	} else {
		echo "No, it’s cool.";
	}

	echo PHP_EOL;

}

echo sprintf( "We will upgrade %d droplets:\n", count( $shutdown_droplets ) );

foreach ( $shutdown_droplets as $key => $droplet ) {

	echo sprintf( "Shutting down %s (%d).\n", $droplet->name, $droplet->id );

	$shutdown_request_body = new stdClass();
	$shutdown_request_body->id = $droplet->id;
	$shutdown_request_body->type = 'shutdown';

	$shutdown_response = Request::post( DO_API_URL_BASE . 'droplets/' . $droplet->id . '/actions' )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->body( json_encode( $shutdown_request_body ) )->send();

	$shutdown_droplets[ $key ]->action_id = $shutdown_response->body->action->id;

}

$done = 0 === count( $shutdown_droplets );

while ( ! $done ) {

	echo ( "We’re not done. Let’s do some checks’n’stuff!\n" );

	// Take it cool. These steps will take some time.
	echo ( "Let’s wait 10 seconds. We’re cool!\n" );
	sleep( 10 );

	echo sprintf( "We got %s droplets in the shutdown stack.\n", count( $shutdown_droplets ) );
	foreach ( $shutdown_droplets as $key => $droplet ) {

		echo sprintf( "Checking action id: %s for %s (%s)\n", $droplet->action_id, $droplet->name, $droplet->id );
		$action_response = Request::get( DO_API_URL_BASE . 'actions/' . $droplet->action_id )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->send();

		$droplet_is_shutdown = $action_error = false;

		switch ( $action_response->body->action->status ) {
			case 'completed':
				$droplet_is_shutdown = true;
				break;

			case 'errored':
				$action_error = true;
				break;

			case 'in-progress':
				break;
			
			default:
				echo "Unknown status: \n";
				var_dump( $action_response->body->action->status );
				break;
		}
		
		if ( $droplet_is_shutdown ) {

			// Start the upgrade.
			echo sprintf( "Issuing upgrade of %s (%s) to %s.\n", $droplet->name, $droplet->id, $droplet->new_plan );
			$resize_request_body = new stdClass();
			$resize_request_body->type = 'resize';
			$resize_request_body->disk = true;
			$resize_request_body->size = $droplet->new_plan;
			$resize_response = Request::post( DO_API_URL_BASE . 'droplets/' . $droplet->id . '/actions' )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->body( json_encode( $resize_request_body ) )->send();
			if ( isset( $resize_response->body->action->id ) ) {
				$droplet->action_id = $resize_response->body->action->id;
			} else {
				$action_error = true;
				if ( isset( $resize_response->body->message ) ) {
					$droplet->action_error_msg = $resize_response->body->message;
				}
			}

			if ( ! $action_error ) {
				// Add the droplet to the migration stack.
				$migrate_droplets[] = $droplet;

				// Remove the droplet from the shutdown stack.
				unset( $shutdown_droplets[ $key ] );
			}
		}

		if ( $action_error ) {
			// CRAP! Try to power cycle the droplet and remove it from the stack.
			echo sprintf( "We had an issue with %s (%s) and will power cycle it.\n", $droplet->name, $droplet->id );
			$power_cycle_request_body = new stdClass();
			$power_cycle_request_body->id = $droplet->id;
			$power_cycle_request_body->type = 'power_cycle';
			Request::post( DO_API_URL_BASE . 'droplets/' . $droplet->id . '/actions' )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->body( json_encode( $power_cycle_request_body ) )->send();
			
			// Add the droplet to the error stack.
			$droplet_errors[] = $droplet;

			// Remove the droplet from the shutdown stack.
			unset( $shutdown_droplets[ $key ] );
		}
	}

	echo sprintf( "We got %s droplets in the migration stack.\n", count( $migrate_droplets ) );
	foreach ( $migrate_droplets as $key => $droplet ) {

		echo sprintf( "Checking action id: %s for %s (%s)\n", $droplet->action_id, $droplet->name, $droplet->id );
		$action_response = Request::get( DO_API_URL_BASE . 'actions/' . $droplet->action_id )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->send();

		$droplet_is_upgraded = $action_error = false;

		switch ( $action_response->body->action->status ) {
			case 'completed':
				$droplet_is_upgraded = true;
				break;

			case 'errored':
				$action_error = true;
				break;

			case 'in-progress':
				break;
			
			default:
				echo "Unknown status: \n";
				var_dump( $action_response->body->action->status );
				break;
		}
		
		if ( $droplet_is_upgraded ) {

			// Boot the droplet.
			$poweron_request_body = new stdClass();
			$poweron_request_body->type = 'power_on';
			$poweron_response = Request::post( DO_API_URL_BASE . 'droplets/' . $droplet->id . '/actions' )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->body( json_encode( $poweron_request_body ) )->send();
			if ( isset( $poweron_response->body->action->id ) ) {
				$droplet->action_id = $poweron_response->body->action->id;
			} else {
				$action_error = true;
				if ( isset( $poweron_response->body->message ) ) {
					$droplet->action_error_msg = $poweron_response->body->message;
				}
			}

			if ( ! $action_error ) {
				// Add the droplet to the boot stack.
				$boot_droplets[] = $droplet;

				// Remove the droplet from the migration stack.
				unset( $migrate_droplets[ $key ] );
			}
		}

		if ( $action_error ) {
			// CRAP! Try to power cycle the droplet and remove it from the stack.
			echo sprintf( "We had an issue with %s (%s) and will power cycle it.\n", $droplet->name, $droplet->id );
			$power_cycle_request_body = new stdClass();
			$power_cycle_request_body->id = $droplet->id;
			$power_cycle_request_body->type = 'power_cycle';
			Request::post( DO_API_URL_BASE . 'droplets/' . $droplet->id . '/actions' )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->body( json_encode( $power_cycle_request_body ) )->send();
			
			// Add the droplet to the error stack.
			$droplet_errors[] = $droplet;

			// Remove the droplet from the migration stack.
			unset( $migrate_droplets[ $key ] );
		}

	}

	echo sprintf( "We got %s droplets in the boot stack.\n", count( $boot_droplets ) );
	foreach ( $boot_droplets as $key => $droplet ) {

		echo sprintf( "Checking action id: %s for %s (%s)\n", $droplet->action_id, $droplet->name, $droplet->id );
		$action_response = Request::get( DO_API_URL_BASE . 'actions/' . $droplet->action_id )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->send();

		$droplet_is_booted = $action_error = false;

		switch ( $action_response->body->action->status ) {
			case 'completed':
				$droplet_is_booted = true;
				break;

			case 'errored':
				$action_error = true;
				break;

			case 'in-progress':
				break;
			
			default:
				echo "Unknown status: \n";
				var_dump( $action_response->body->action->status );
				break;
		}
		
		if ( $droplet_is_booted ) {
			// Add the droplet to the done stack.
			$done_droplets[] = $droplet;

			// Remove the droplet from the boot stack.
			unset( $boot_droplets[ $key ] );
		}

		if ( $action_error ) {
			// CRAP! Try to power cycle the droplet and remove it from the stack.
			echo sprintf( "We had an issue with %s (%s) and will power cycle it.\n", $droplet->name, $droplet->id );
			$power_cycle_request_body = new stdClass();
			$power_cycle_request_body->id = $droplet->id;
			$power_cycle_request_body->type = 'power_cycle';
			Request::post( DO_API_URL_BASE . 'droplets/' . $droplet->id . '/actions' )->sendsJson()->addHeader( 'Authorization', 'Bearer ' . DO_ACCESS_TOKEN )->body( json_encode( $power_cycle_request_body ) )->send();
			
			// Add the droplet to the error stack.
			$droplet_errors[] = $droplet;

			// Remove the droplet from the boot stack.
			unset( $boot_droplets[ $key ] );
		}
	}

	echo sprintf( "We got %s droplets in the done stack.\n", count( $done_droplets ) );

	if ( 0 === count( $shutdown_droplets ) && 0 === count( $migrate_droplets ) && 0 === count( $boot_droplets ) ) {
		$done = true;
	}

}

if ( count( $droplet_errors ) ) {
	echo sprintf( "We had an issue with the following droplets, and have tried to power cycle them. Please check their status manually. You may retry running this script when they are back up.\n" );
	foreach ( $droplet_errors as $droplet ) {
		echo sprintf( "%s (%s) - %s: https://cloud.digitalocean.com/droplets/%s\n", $droplet->name, $droplet->id, ( isset( $droplet->action_error_msg ) ? $droplet->action_error_msg : 'Unkonwn error' ) , $droplet->id );
	}
}

echo "Done!\n";
