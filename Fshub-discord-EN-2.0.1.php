<?php
/**
 * Plugin Name: FSHub → Discord Webhook
 * Description: Receives FSHub webhooks and sends them to Discord.
 * Version: 2.0.1
 * Author: Rackham
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function() {
    register_setting('fshub_settings_group', 'fshub_discord_webhook_url');
});


// -----------------------------------------------------------------------------
// SECTION: INSTALLATION AND LOGGING
// -----------------------------------------------------------------------------

function fshub_va_plugin_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'fshub_logs';
    
    //Chgck if table already exists, continue if not.
    $table_check = $table_name->prepare(
        "SHOW TABLES LIKE %s",
        $table_name
    );

    $table_check_result = $table_name->get_var($table_check);
    return ( $table_check_result === $table_name );

    if ( tablealreadyexists($table_name)) { 
        break; //All good, let's continue with Installation
    } else {
        //Display Modal here
    }

    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        timestamp datetime NOT NULL,
        type varchar(50) NOT NULL,
        status varchar(50) NOT NULL,
        payload_id varchar(100),
        log_message text,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'fshub_va_plugin_install' );

function log_event($type, $status, $message, $payload_id = '') {
    global $wpdb;
    $wpdb->insert( 
        $wpdb->prefix . 'fshub_logs', 
        array( 
            'timestamp'    => current_time( 'mysql' ), 
            'type'         => $type, 
            'status'       => $status,
            'payload_id'   => $payload_id,
            'log_message'  => $message
        ) 
    );
}


class FSHub_Discord_Webhook {

	private function get_webhook_url() {
		return get_option('fshub_discord_webhook_url');
	}

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_fshub_test_webhook', [$this, 'handle_test_notification']);
    }

    public function register_routes() {
        register_rest_route('fshub/v1', '/webhook', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    // -------------------------------------------------------------------------
    // SECTION: WEBHOOK HANDLING (with Logging and 5s Timeout)
    // -------------------------------------------------------------------------

    public function handle_webhook(WP_REST_Request $request) {
       
        $raw  = $request->get_body();
        $body = json_decode($raw, true);
        $payload_id = $body['flight_id'] ?? $body['id'] ?? null;

        log_event('webhook_received', 'success', 'Request received', $payload_id);

        if (!$body) {
            log_event('webhook_received', 'failed', 'Invalid JSON', $payload_id);
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid JSON'], 400);
        }

        $type = $body['_type'] ?? null;
        $d    = $body['_data'] ?? [];

        $allowed = [
            'flight.departed',
            'flight.completed',
            'airline.achievement',
            'screenshots.uploaded',
        ];

        if (!in_array($type, $allowed, true)) {
            log_event('webhook_received', 'ignored', "Ignored event type: {$type}", $payload_id);
            return new WP_REST_Response(['status' => 'ignored'], 200);
        }

        $payload = $this->build_discord_payload($type, $d);

        $webhook = $this->get_webhook_url();

        if (empty($webhook)) {
            log_event('discord_sent', 'failed', "Missing Discord webhook", $payload_id);
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'The Discord webhook is not configured.'
            ], 500);
        }

        $response = wp_remote_post($webhook, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode($payload),
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            log_event('discord_sent', 'failed', "Error sending to Discord: {$error_message}", $payload_id);
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => $error_message
            ], 500);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 204 || $response_code === 200) {
            log_event('discord_sent', 'success', "Discord message sent (Code: {$response_code})", $payload_id);
        } else {
            $response_body = wp_remote_retrieve_body($response);
            log_event('discord_sent', 'failed', "Discord error (Code: {$response_code}): {$response_body}", $payload_id);
        }

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    // -------------------------------------------------------------------------
    // SECTION: ADMIN MENU AND STATISTICS
    // -------------------------------------------------------------------------

    public function add_admin_menu() {
        add_menu_page(
            'FsHub VA Stats',
            'FsHub VA Stats',
            'manage_options',
            'fshub-stats',
            [$this, 'stats_page_content'],
            'dashicons-chart-area',
            80
        );
    }

    public function stats_page_content() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fshub_logs';

        $webhooks_recus = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE type LIKE 'webhook_received' AND status = 'success';");
        $discord_ok = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE type = 'discord_sent' AND status = 'success';");
        $erreurs_discord = $wpdb->get_var("SELECT COUNT(id) FROM $table_name WHERE type = 'discord_sent' AND status = 'failed';");

        ?>
        <div class="wrap">
            <h1>FsHub VA Statistics & Tests</h1>

            <?php if (isset($_GET['test_sent'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Test notification <strong><?php echo esc_html(sanitize_text_field($_GET['test_sent'])); ?></strong> sent to Discord! Check the channel.</p>
                </div>
            <?php endif; ?>

            <h2>Logs overview</h2>
            <div style="display: flex; gap: 20px;">
                <div style="background: #fff; padding: 20px; border-left: 5px solid #0073aa; flex: 1;">
                    <h3>Webhooks received</h3>
                    <p style="font-size: 2em; font-weight: bold;"><?php echo esc_html($webhooks_recus); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border-left: 5px solid #46b450; flex: 1;">
                    <h3>Successful Discord sends</h3>
                    <p style="font-size: 2em; font-weight: bold;"><?php echo esc_html($discord_ok); ?></p>
                </div>
                <div style="background: #fff; padding: 20px; border-left: 5px solid #dc3232; flex: 1;">
                    <h3>Send errors</h3>
                    <p style="font-size: 2em; font-weight: bold;"><?php echo esc_html($erreurs_discord); ?></p>
                </div>
            </div>

            <hr>
			
			<h2>Discord Webhook Settings</h2>

			<form method="post" action="options.php">
				<?php settings_fields('fshub_settings_group'); ?>
				<?php do_settings_sections('fshub_settings_group'); ?>

				<table class="form-table">
					<tr valign="top">
						<th scope="row">Discord Webhook URL</th>
						<td>
							<input type="text"
								name="fshub_discord_webhook_url"
								value="<?php echo esc_attr(get_option('fshub_discord_webhook_url')); ?>"
								class="regular-text" />
						</td>
					</tr>
				</table>

				<?php submit_button('Save'); ?>
			</form>
			
			<hr>
			
			<h2>Webhook to configure in FSHub</h2>

			<div style="background:#fff;padding:15px;border-left:4px solid #0073aa;margin-bottom:20px;">
				<p><strong>URL to use in FSHub:</strong></p>

				<?php 
					$fshub_webhook_url = home_url('/wp-json/fshub/v1/webhook');
				?>

				<input type="text"
					   readonly
					   value="<?php echo esc_attr($fshub_webhook_url); ?>"
					   onclick="this.select();"
					   style="width:100%;padding:6px;font-size:14px;">
			</div>

			<hr>

            <h2>Test Discord Notifications</h2>
            <p>Click to send a test notification for each event type.</p>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
			<?php $this->render_test_button('flight.departed', 'Test Departure 🛫', 'button-primary'); ?>
			<?php $this->render_test_button('flight.completed', 'Test Arrival 🛬', 'button-primary'); ?>
			<?php $this->render_test_button('airline.achievement', 'Test Achievement 🏆', 'button-primary'); ?>
			<?php $this->render_test_button('screenshots.uploaded', 'Test Screenshot 📸', 'button-primary'); ?>
			</div>

            <p style="margin-top: 15px; font-size: 0.9em;">*Tests are recorded in the logs. The data is fictional.</p>

        </div>
        <?php
    }

    private function render_test_button($type, $label, $class) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="fshub_test_webhook">
            <?php wp_nonce_field('fshub_test_action'); ?>
            <input type="hidden" name="test_type" value="<?php echo esc_attr($type); ?>">
            <button type="submit" class="button <?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

	// -------------------------------------------------------------------------
    // SECTION: TEST HANDLING AND SIMULATION
    // -------------------------------------------------------------------------

    public function handle_test_notification() {
        if (!current_user_can('manage_options') ||
			!isset($_POST['_wpnonce']) ||
			!wp_verify_nonce($_POST['_wpnonce'], 'fshub_test_action')
		) {
			wp_die('Sorry, you are not allowed to perform this action.');
		}

        $test_type = isset($_POST['test_type']) ? sanitize_text_field($_POST['test_type']) : '';

        $fake_data = $this->get_fake_test_data($test_type);

        $payload = $this->build_discord_payload($test_type, $fake_data);
        
        $status = 'failed';
        $message = 'Unknown error';

        if (isset($payload['embeds']) && !empty($payload['embeds'])) {

            $webhook = $this->get_webhook_url();

            if (empty($webhook)) {
                $status = 'failed';
                $message = 'Missing Discord webhook';
            } else {
                $response = wp_remote_post($webhook, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => wp_json_encode($payload),
                    'timeout' => 5, // Timeout applied to the test
                ]);
                
                $status = is_wp_error($response) ? 'failed' : 'success';
                $message = is_wp_error($response) ? $response->get_error_message() : 'Test successful';
            }

        } else {
            $message = 'Error: The test type is unknown or did not generate an embed.';
        }

        log_event('discord_test_sent', $status, "Discord notification test: {$test_type} ({$message})", 'TEST-'.time());

        wp_redirect(admin_url('admin.php?page=fshub-stats&test_sent=' . $test_type));
        exit;
    }

	private function get_fake_test_data($type) {


		$base = [
			'id' => 'TEST-12345',
			'user' => [
				'name' => 'Test Pilot'
			],
			'aircraft' => [
				'icao' => 'B738',
				'user_conf' => [
					'tail' => 'F-TEST'
				]
			],
			'plan' => [
				'callsign'   => 'AFU1234',
				'flight_no'  => 'AFU1234',
				'departure'  => 'LFPG',
				'arrival'    => 'KJFK',
				'route'      => 'NIZAR UT420 GIPNO NATU',
				'cruise_lvl' => 370,
				'icao_dep'   => 'LFPG',
				'icao_arr'   => 'KJFK'
			],
			'distance' => [
				'nm' => 3100,
				'km' => 5741
			],
			'fuel_burnt' => 15500,
		];

		switch ($type) {

			case 'flight.departed':
				return array_merge($base, [
					'airport' => [
						'icao' => 'LFPG',
						'name' => 'Paris/CDG',
						'locale' => [
							'city'    => 'Paris',
							'country' => 'France'
						]
					],
					'weight' => [
						'fuel' => 18000,
						'zfw'  => 60000
					],
					'heading' => [
						'true' => 350
					],
					'wind' => [
						'speed'     => 15,
						'direction' => 300
					],
					'schedule' => [
						'time'   => '10:30Z',
						'status' => 'Taxiing'
					],
					'speed_tas' => 150,
				]);

			case 'flight.completed':
				return array_merge($base, [

					'departure' => [
						'airport' => [
							'name' => 'Paris/CDG'
						]
					],

					'arrival' => [
						'airport' => [
							'name' => 'New York/JFK'
						],
						'landing_rate' => -155,
						'pitch'        => 2.5,
						'bank'         => -0.5,
						'wind'         => [
							'speed'     => 10,
							'direction' => 270
						]
					],

					'schedule_status' => 'Arrived on time',
				]);

			case 'airline.achievement':
    return [
        'achievement' => [
            'id'          => 23195,
            'title'       => 'Visit Columbus!',
            'slug'        => '281-visit-columbus',
            'description' => 'A test achievement for documentation purposes!'
        ],
        'flight' => [
            'id' => 2707468,
            'user' => [
                'id'   => 2,
                'name' => 'Test Pilot',
                'profile' => [
                    'avatar_url' => 'https://g.fshubcdn.com/avatars/u_2_80.png',
                ]
            ],
            'aircraft' => [
                'icao' => 'BE36',
                'icao_name' => 'Beechcraft G36 Bonanza',
                'user_conf' => [
                    'tail' => 'F-TEST'
                ]
            ],
            'plan' => [
                'callsign'   => 'YON112',
                'cruise_lvl' => 60,
                'route'      => 'KMCN DCT KCSG',
                'icao_dep'   => 'KMCN',
                'icao_arr'   => 'KCSG'
            ],
            'departure' => [
                'airport' => [
                    'icao' => 'KMCN',
                    'name' => 'Middle Georgia Regl'
                ]
            ],
            'arrival' => [
                'airport' => [
                    'icao' => 'KCSG',
                    'name' => 'Columbus Metro'
                ],
                'landing_rate' => -81,
                'pitch'        => -3,
                'bank'         => 0,
            ]
        ],
        'airline' => [
            'id'   => 281,
            'name' => 'Yondair',
            'profile' => [
                'abbreviation' => 'YON'
            ]
        ]
    ];

			case 'screenshots.uploaded':
				return [
					[
						'flight_id'     => 'TEST-12345',
						'screenshot_url'=> 'https://picsum.photos/800/600',
						'lat'           => 48.72,
						'lng'           => 2.37,
						'datetime'      => date('Y-m-d H:i:s')
					]
				];

			default:
				return $base;
		}
	}


    // -------------------------------------------------------------------------
    // SECTION: ROUTER (Formatting logic unchanged)
    // -------------------------------------------------------------------------

    private function build_discord_payload($type, $d) {
        switch ($type) {
            case 'flight.departed':
                return $this->format_departure($d);
            case 'flight.completed':
                return $this->format_flight_report($d);
            case 'airline.achievement':
                return $this->format_airline_achievement($d);
            case 'screenshots.uploaded':
                return $this->format_screenshot($d);
            default:
                return [ 'content' => 'Unhandled event' ];
        }
    }

// -----------------------------------------------------------------------------
// HELPERS
// -----------------------------------------------------------------------------

private function safe($v) {
    return isset($v) && $v !== '' ? (string)$v : 'N/A';
}

private function landing_style($rate) {
    if (!isset($rate)) return 'N/A';
    $r = (float)$rate;
    if ($r > -120) return 'Ultra smooth';
    if ($r > -220) return 'Soft';
    if ($r > -350) return 'Normal';
    if ($r > -500) return 'Firm';
    return 'Hard landing';
}

private function format_airport($a) {
    $icao = $this->safe($a['icao'] ?? null);
    $name = $this->safe($a['name'] ?? null);

    $city    = $a['locale']['city']    ?? '';
    $country = $a['locale']['country'] ?? '';

    $suffix = '';
    if ($city || $country) {
        $suffix = ' (';
        if ($city)    $suffix .= $city;
        if ($country) $suffix .= ($city ? ', ' : '') . $country;
        $suffix .= ')';
    }

    return "{$icao} – {$name}{$suffix}";
}


// -----------------------------------------------------------------------------
// FORMAT: DEPARTURE
// -----------------------------------------------------------------------------

private function format_departure($d) {
    $user     = $d['user'] ?? [];
    $aircraft = $d['aircraft'] ?? [];
    $plan     = $d['plan'] ?? [];
    $schedule = $d['schedule'] ?? [];
    $airport  = $d['airport'] ?? [];
    $weight   = $d['weight'] ?? [];
    $heading  = $d['heading'] ?? [];
    $wind     = $d['wind'] ?? [];

	$userName    = $user['name'] ?? 'A pilot';
	$airportName = $airport['name'] ?? ($plan['departure'] ?? 'an airport');

	$title = "🛫 {$userName} just departed from **{$airportName}**";

    $fields = [];

    // Registration
    $tail = $aircraft['user_conf']['tail'] ?? null;

    // Pilot
    $fields[] = [
        'name'   => '👨‍✈️ Pilot',
        'value'  => $this->safe($user['name'] ?? null),
        'inline' => true,
    ];

    // Aircraft
    $fields[] = [
        'name'   => '✈️ Aircraft',
        'value'  => $this->safe($aircraft['icao'] ?? null),
        'inline' => true,
    ];

    // Registration
    $fields[] = [
        'name'   => '🔢 Registration',
        'value'  => $this->safe($tail ?? null),
        'inline' => true,
    ];

    // Route & flight level
    $fields[] = [
        'name'   => "\u{200B}",
        'value'  => '**🗺️ Route & Flight level**',
        'inline' => false,
    ];

    $fields[] = [
        'name'   => '📍 Departure',
        'value'  => $this->safe($plan['departure'] ?? $airport['icao'] ?? null),
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '🎯 Destination',
        'value'  => $this->safe($plan['arrival'] ?? null),
        'inline' => true,
    ];

    $cruise = isset($plan['cruise_lvl'])
        ? "FL{$plan['cruise_lvl']} (" . ($plan['cruise_lvl'] * 100) . " ft)"
        : 'N/A';

    $fields[] = [
        'name'   => '🏔️ Cruise altitude',
        'value'  => $cruise,
        'inline' => true,
    ];

    // Navigation data
    $fields[] = [
        'name'   => "\u{200B}",
        'value'  => '**✈️ Navigation data**',
        'inline' => false,
    ];

    $fields[] = [
        'name'   => '⚡ TAS',
        'value'  => isset($d['speed_tas']) ? $d['speed_tas'] . ' kt' : 'N/A',
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '🧭 Heading',
        'value'  => isset($heading['true']) ? $heading['true'] . '°' : 'N/A',
        'inline' => true,
    ];

    if (isset($wind['speed'])) {
        $windVal = $wind['speed'] . ' kt @ ' . ($wind['direction'] ?? 'N/A') . '°';
    } else {
        $windVal = 'N/A';
    }

    $fields[] = [
        'name'   => '💨 Wind',
        'value'  => $windVal,
        'inline' => true,
    ];

    // Weights
    $fields[] = [
        'name'   => "\u{200B}",
        'value'  => '**📦 Weights**',
        'inline' => false,
    ];

    $fields[] = [
        'name'   => '⛽ Fuel',
        'value'  => isset($weight['fuel']) ? $weight['fuel'] . ' kg' : 'N/A',
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '⚖️ ZFW',
        'value'  => isset($weight['zfw']) ? $weight['zfw'] . ' kg' : 'N/A',
        'inline' => true,
    ];

    $time   = $this->safe($schedule['time'] ?? null);
    $status = $this->safe($schedule['status'] ?? null);

    $fields[] = [
        'name'   => '🕒 Schedule',
        'value'  => "{$time} • {$status}",
        'inline' => false,
    ];

    return [
        'embeds' => [[
            'title'       => $title,
            'description' => '',
            'color'       => 0x1d9bf0,
            'fields'      => $fields,
            'footer'      => [ 'text' => 'FSHub • flight.departed' ],
        ]]
    ];
}


// -----------------------------------------------------------------------------
// FORMAT: FLIGHT REPORT / ARRIVAL
// -----------------------------------------------------------------------------

private function format_flight_report($d) {
    $user      = $d['user'] ?? [];
    $aircraft  = $d['aircraft'] ?? [];
    $plan      = $d['plan'] ?? [];
    $departure = $d['departure'] ?? [];
    $arrival   = $d['arrival'] ?? [];
    $distance  = $d['distance'] ?? [];

	$userName = $user['name'] ?? 'A pilot';
	$airportName = $arrival['airport']['name']
				?? ($plan['icao_arr'] ?? 'an airport');

	$title = "🛬 {$userName} just landed at **{$airportName}**";

    $fields = [];

    // Registration and callsign
    $tail = $aircraft['user_conf']['tail'] ?? null;
    $flightNumber = $plan['flight_no'] ?? $plan['callsign'] ?? null;

    $flightId = $d['flight_id'] ?? $d['id'] ?? null;
    if ($flightId) {
        $url = "https://fshub.io/flight/{$flightId}/report";
        $fields[] = [
            'name'   => '🔗 Flight report',
            'value'  => "[View flight]({$url})",
            'inline' => false,
        ];
    }

    $fields[] = [
        'name'   => "\u{200B}",
        'value'  => '**💺 Flight information**',
        'inline' => false,
    ];

    $fields[] = [
        'name'   => '👨‍✈️ Pilot',
        'value'  => $this->safe($user['name'] ?? null),
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '✈️ Aircraft',
        'value'  => $this->safe($aircraft['icao'] ?? null),
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '🔢 Registration',
        'value'  => $this->safe($tail ?? null),
        'inline' => true,
    ];

    $callsignField = $flightNumber ?: 'N/A';

    $fields[] = [
        'name'   => '📡 Callsign',
        'value'  => $callsignField,
        'inline' => true,
    ];

    $depIcao = $plan['icao_dep'] ?? null;
    $depName = $departure['airport']['name'] ?? null;

    if ($depIcao && $depName) {
        $depVal = "{$depIcao} – {$depName}";
    } elseif ($depIcao) {
        $depVal = $depIcao;
    } elseif ($depName) {
        $depVal = $depName;
    } else {
        $depVal = 'N/A';
    }

    $fields[] = [
        'name'   => '🛫 Departure',
        'value'  => $depVal,
        'inline' => true,
    ];

    $arrIcao = $plan['icao_arr'] ?? null;
    $arrName = $arrival['airport']['name'] ?? null;

    if ($arrIcao && $arrName) {
        $arrVal = "{$arrIcao} – {$arrName}";
    } elseif ($arrIcao) {
        $arrVal = $arrIcao;
    } elseif ($arrName) {
        $arrVal = $arrName;
    } else {
        $arrVal = 'N/A';
    }

    $fields[] = [
        'name'   => '🛬 Arrival',
        'value'  => $arrVal,
        'inline' => true,
    ];

    $fields[] = [
        'name'   => "\u{200B}",
        'value'  => '**✈️ Flight data**',
        'inline' => false,
    ];

    $cruise = isset($plan['cruise_lvl'])
        ? "FL{$plan['cruise_lvl']} (" . ($plan['cruise_lvl'] * 100) . " ft)"
        : 'N/A';

    $fields[] = [
        'name'   => '🛫 Cruise altitude',
        'value'  => $cruise,
        'inline' => true,
    ];

    if (isset($distance['nm'])) {
        $dist = "{$distance['nm']} NM";
        if (isset($distance['km'])) {
            $dist .= " ({$distance['km']} km)";
        }
    } else {
        $dist = 'N/A';
    }

    $fields[] = [
        'name'   => '🧭 Distance',
        'value'  => $dist,
        'inline' => true,
    ];

    $fuelBurn = isset($d['fuel_burnt']) ? $d['fuel_burnt'] . ' kg' : 'N/A';

    $fields[] = [
        'name'   => '🔥 Fuel burned',
        'value'  => $fuelBurn,
        'inline' => true,
    ];

    $route = $plan['route'] ?? null;

    $fields[] = [
        'name'   => '🗺️ Planned route',
        'value'  => $route ? "`{$route}`" : 'N/A',
        'inline' => false,
    ];

    $fields[] = [
        'name'   => "\u{200B}",
        'value'  => '**🛬 Landing & Weather**',
        'inline' => false,
    ];

    if (isset($arrival['landing_rate'])) {
        $lr = $arrival['landing_rate'];
        $lrTxt = "{$lr} fpm";
        $lrStyle = $this->landing_style($lr);
    } else {
        $lrTxt = 'N/A';
        $lrStyle = 'N/A';
    }

    $fields[] = [
        'name'   => '🛬 Landing rate',
        'value'  => $lrTxt,
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '🎯 Landing quality',
        'value'  => $lrStyle,
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '↕️ Pitch',
        'value'  => isset($arrival['pitch']) ? $arrival['pitch'] . '°' : 'N/A',
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '🔄️ Bank',
        'value'  => isset($arrival['bank']) ? $arrival['bank'] . '°' : 'N/A',
        'inline' => true,
    ];

    if (isset($arrival['wind']['speed'])) {
        $windTxt = "{$arrival['wind']['speed']} kt @ " .
                   ($arrival['wind']['direction'] ?? 'N/A') . "°";
    } else {
        $windTxt = 'N/A';
    }

    $fields[] = [
        'name'   => '💨 Wind',
        'value'  => $windTxt,
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '📅 Status',
        'value'  => $this->safe($d['schedule_status'] ?? null),
        'inline' => true,
    ];

    return [
        'embeds' => [[
            'title'  => $title,
            'color'  => 0x3aa655,
            'fields' => $fields,
            'footer' => [
                'text' => 'FSHub • flight.completed'
            ],
        ]]
    ];
}

// -----------------------------------------------------------------------------
// FORMAT: AIRLINE ACHIEVEMENT
// -----------------------------------------------------------------------------

private function format_airline_achievement($d) {

    $ach     = $d['achievement'] ?? [];
    $flight  = $d['flight'] ?? [];
    $user    = $flight['user'] ?? [];

    $pilot     = $this->safe($user['name'] ?? null);
    $titleAch  = $this->safe($ach['title'] ?? null);
    $descFull  = $this->safe($ach['description'] ?? null);

    $achId     = $ach['id'] ?? null;
    $slug      = $ach['slug'] ?? null;

    // Correct FsHub URL: achievement/<id>-<slug>/overview
    $achievementUrl = ($achId && $slug)
        ? "https://fshub.io/achievement/{$achId}-{$slug}/overview"
        : null;

    // Short description (~160 chars)
    $maxLen = 160;
    if ($descFull !== 'N/A' && strlen($descFull) > $maxLen) {
        $cut = substr($descFull, 0, $maxLen);
        $cut = preg_replace('/\s+[^ ]*$/', '', $cut);
        $descShort = $cut . '…';
    } else {
        $descShort = $descFull;
    }

    // Title
    $title = "🏅 **{$pilot} unlocked the achievement “{$titleAch}”!**";

    $fields = [];

    // Description
    $fields[] = [
        'name'   => '📖 Description',
        'value'  => $descShort,
        'inline' => false,
    ];

    // FsHub link
    if ($achievementUrl) {
        $fields[] = [
            'name'   => '🔗 View achievement',
            'value'  => "[Open on FsHub]({$achievementUrl})",
            'inline' => false,
        ];
    }

    // Congratulations
    $fields[] = [
        'name'   => '🎉 Congratulations',
        'value'  => "Well done **{$pilot}**! 🎊",
        'inline' => false,
    ];

    return [
        'embeds' => [[
            'title'  => $title,
            'color'  => 0xffcc00,
            'fields' => $fields,
            'footer' => [
                'text' => 'FSHub • airline.achievement'
            ],
        ]]
    ];
}


// -----------------------------------------------------------------------------
// FORMAT: SCREENSHOT
// -----------------------------------------------------------------------------

private function format_screenshot($d) {
    $s = is_array($d) && isset($d[0]) ? $d[0] : $d;

    $flightId  = $s['flight_id'] ?? null;
    $flightUrl = $flightId ? "https://fshub.io/flight/{$flightId}/report" : null;

    $coords = (isset($s['lat']) && isset($s['lng']))
        ? "{$s['lat']}, {$s['lng']}"
        : 'N/A';

    if (!empty($s['datetime'])) {
        try {
            $dt = new DateTime($s['datetime']);
            $datetime = $dt->format('Y-m-d H:i:s') . ' UTC';
        } catch (Exception $e) {
            $datetime = 'N/A';
        }
    } else {
        $datetime = 'N/A';
    }

    $fields = [];

    $fields[] = [
        'name'   => '🕒 Time',
        'value'  => $datetime,
        'inline' => true,
    ];

    $fields[] = [
        'name'   => '🧭 Coordinates',
        'value'  => $coords,
        'inline' => true,
    ];

    if ($flightUrl) {
        $fields[] = [
            'name'   => '🔗 Flight link',
            'value'  => "[View flight]({$flightUrl})",
            'inline' => false,
        ];
    }

    $embed = [
        'title'  => '📸 New screenshots have been uploaded',
        'color'  => 0x87ceeb,
        'fields' => $fields,
        'footer' => [ 'text' => 'FSHub • Screenshot' ],
    ];

    if (!empty($s['screenshot_url'])) {
        $embed['image'] = ['url' => $s['screenshot_url']];
    }

    return [
        'embeds' => [ $embed ],
    ];
	}

}

	new FSHub_Discord_Webhook();
