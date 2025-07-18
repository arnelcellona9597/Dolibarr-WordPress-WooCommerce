<?php
namespace Automattic\WooCommerce\Blocks\Shipping;

use Automattic\WooCommerce\Blocks\Assets\Api as AssetApi;
use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\Enums\ProductTaxStatus;
use Automattic\WooCommerce\StoreApi\Utilities\LocalPickupUtils;
use Automattic\WooCommerce\Utilities\ArrayUtil;
use WC_Customer;
use WC_Shipping_Rate;
use WC_Tracks;

/**
 * ShippingController class.
 *
 * @internal
 */
class ShippingController {

	/**
	 * Script handle used for enqueueing the scripts needed for managing the Local Pickup Shipping Settings.
	 */
	private const LOCAL_PICKUP_ADMIN_JS_HANDLE = 'wc-shipping-method-pickup-location';

	/**
	 * Instance of the asset API.
	 *
	 * @var AssetApi
	 */
	protected $asset_api;

	/**
	 * Instance of the asset data registry.
	 *
	 * @var AssetDataRegistry
	 */
	protected $asset_data_registry;

	/**
	 * Whether local pickup is enabled.
	 *
	 * @var bool
	 */
	private $local_pickup_enabled;

	/**
	 * Constructor.
	 *
	 * @param AssetApi          $asset_api Instance of the asset API.
	 * @param AssetDataRegistry $asset_data_registry Instance of the asset data registry.
	 */
	public function __construct( AssetApi $asset_api, AssetDataRegistry $asset_data_registry ) {
		$this->asset_api            = $asset_api;
		$this->asset_data_registry  = $asset_data_registry;
		$this->local_pickup_enabled = LocalPickupUtils::is_local_pickup_enabled();
	}

	/**
	 * Initialization method.
	 */
	public function init() {
		if ( is_admin() ) {
			$this->asset_data_registry->add(
				'countryStates',
				function () {
					return WC()->countries->get_states();
				}
			);
		}
		$this->asset_data_registry->add( 'shippingCostRequiresAddress', get_option( 'woocommerce_shipping_cost_requires_address', false ) === 'yes' );
		add_action( 'rest_api_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'admin_footer', array( $this, 'hydrate_client_settings' ), 0 );
		add_action( 'woocommerce_load_shipping_methods', array( $this, 'register_local_pickup' ) );
		add_filter( 'woocommerce_local_pickup_methods', array( $this, 'register_local_pickup_method' ) );
		add_filter( 'woocommerce_order_hide_shipping_address', array( $this, 'hide_shipping_address_for_local_pickup' ), 10 );
		add_filter( 'woocommerce_customer_taxable_address', array( $this, 'filter_taxable_address' ) );
		add_filter( 'woocommerce_shipping_settings', array( $this, 'remove_shipping_settings' ) );
		add_filter( 'woocommerce_shipping_packages', array( $this, 'filter_shipping_packages' ) );
		add_filter( 'pre_update_option_woocommerce_pickup_location_settings', array( $this, 'flush_cache' ) );
		add_filter( 'pre_update_option_pickup_location_pickup_locations', array( $this, 'flush_cache' ) );
		add_filter( 'woocommerce_shipping_packages', array( $this, 'remove_shipping_if_no_address' ), 11 );
		add_filter( 'woocommerce_order_shipping_to_display', array( $this, 'show_local_pickup_details' ), 10, 2 );
		add_action( 'rest_pre_serve_request', array( $this, 'track_local_pickup' ), 10, 4 );
	}

	/**
	 * Inject collection details onto the order received page.
	 *
	 * @param string    $return_value Return value.
	 * @param \WC_Order $order Order object.
	 * @return string
	 */
	public function show_local_pickup_details( $return_value, $order ) {
		// Confirm order is valid before proceeding further.
		if ( ! $order instanceof \WC_Order ) {
			return $return_value;
		}

		$shipping_method_ids = ArrayUtil::select( $order->get_shipping_methods(), 'get_method_id', ArrayUtil::SELECT_BY_OBJECT_METHOD );
		$shipping_method_id  = current( $shipping_method_ids );

		// Ensure order used pickup location method, otherwise bail.
		if ( 'pickup_location' !== $shipping_method_id ) {
			return $return_value;
		}

		$shipping_method = current( $order->get_shipping_methods() );
		$details         = $shipping_method->get_meta( 'pickup_details' );
		$location        = $shipping_method->get_meta( 'pickup_location' );
		$address         = $shipping_method->get_meta( 'pickup_address' );
		$cost            = $shipping_method->get_total();

		$lines = array();

		if ( $location ) {
			$lines[] = sprintf(
				// Translators: %s location name.
				__( 'Collection from <strong>%s</strong>:', 'woocommerce' ),
				$location
			);
		}

		if ( $address ) {
			$lines[] = nl2br( esc_html( str_replace( ',', ', ', $address ) ) );
		}

		if ( $details ) {
			$lines[] = wp_kses_post( $details );
		}

		if ( $cost > 0 ) {
			$tax_display = get_option( 'woocommerce_tax_display_cart' );
			$tax         = $shipping_method->get_total_tax();

			// Format cost with tax handling.
			if ( 'excl' === $tax_display ) {
				// Show pickup cost excluding tax.
				$formatted_cost = wc_price( $cost, array( 'currency' => $order->get_currency() ) );
				if ( (float) $tax > 0 && $order->get_prices_include_tax() ) {
					/**
					 * Hook to add tax label to pickup cost.
					 *
					 * @since 6.0.0
					 * @param string $tax_label Tax label.
					 * @param \WC_Order $order Order object.
					 * @param string $tax_display Tax display.
					 * @return string
					 */
					$formatted_cost .= apply_filters(
						'woocommerce_order_shipping_to_display_tax_label',
						'&nbsp;<small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>',
						$order,
						$tax_display
					);
				}
			} else {
				// Show pickup cost including tax.
				$formatted_cost = wc_price(
					(float) $cost + (float) $tax,
					array( 'currency' => $order->get_currency() )
				);
				if ( (float) $tax > 0 && ! $order->get_prices_include_tax() ) {
					/**
					 * Hook to add tax label to pickup cost.
					 *
					 * @since 6.0.0
					 * @param string $tax_label Tax label.
					 * @param \WC_Order $order Order object.
					 * @param string $tax_display Tax display.
					 * @return string
					 */
					$formatted_cost .= apply_filters(
						'woocommerce_order_shipping_to_display_tax_label',
						'&nbsp;<small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>',
						$order,
						$tax_display
					);
				}
			}

			$lines[] = '<br>' . sprintf(
				// Translators: %s is the formatted price.
				__( 'Pickup cost: %s', 'woocommerce' ),
				$formatted_cost
			);
		}

		// If nothing is available, return original.
		if ( empty( $lines ) ) {
			return $return_value;
		}

		// Join all the lines with a <br> separator.
		return implode( '<br>', $lines );
	}

	/**
	 * When using the cart and checkout blocks this method is used to adjust core shipping settings via a filter hook.
	 *
	 * @param array $settings The default WC shipping settings.
	 * @return array|mixed The filtered settings.
	 */
	public function remove_shipping_settings( $settings ) {
		if ( CartCheckoutUtils::is_cart_block_default() ) {
			foreach ( $settings as $index => $setting ) {
				if ( 'woocommerce_enable_shipping_calc' === $setting['id'] ) {
					$settings[ $index ]['desc_tip'] = sprintf(
					/* translators: %s: URL to the documentation. */
						__( 'This feature is not available when using the <a href="%s">Cart and checkout blocks</a>. Shipping will be calculated at checkout.', 'woocommerce' ),
						'https://woocommerce.com/document/woocommerce-store-editing/customizing-cart-and-checkout/'
					);
					$settings[ $index ]['disabled'] = true;
					$settings[ $index ]['value']    = 'no';
					break;
				}
			}
		}

		return $settings;
	}

	/**
	 * Register Local Pickup settings for rest api.
	 */
	public function register_settings() {
		register_setting(
			'options',
			'woocommerce_pickup_location_settings',
			array(
				'type'         => 'object',
				'description'  => 'WooCommerce Local Pickup Method Settings',
				'default'      => array(),
				'show_in_rest' => array(
					'name'   => 'pickup_location_settings',
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'enabled'    => array(
								'description' => __( 'If enabled, this method will appear on the block based checkout.', 'woocommerce' ),
								'type'        => 'string',
								'enum'        => array( 'yes', 'no' ),
							),
							'title'      => array(
								'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
								'type'        => 'string',
							),
							'tax_status' => array(
								'description' => __( 'If a cost is defined, this controls if taxes are applied to that cost.', 'woocommerce' ),
								'type'        => 'string',
								'enum'        => array( ProductTaxStatus::TAXABLE, ProductTaxStatus::NONE ),
							),
							'cost'       => array(
								'description' => __( 'Optional cost to charge for local pickup.', 'woocommerce' ),
								'type'        => 'string',
							),
						),
					),
				),
			)
		);
		register_setting(
			'options',
			'pickup_location_pickup_locations',
			array(
				'type'         => 'array',
				'description'  => 'WooCommerce Local Pickup Locations',
				'default'      => array(),
				'show_in_rest' => array(
					'name'   => 'pickup_locations',
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'    => array(
									'type' => 'string',
								),
								'address' => array(
									'type'       => 'object',
									'properties' => array(
										'address_1' => array(
											'type' => 'string',
										),
										'city'      => array(
											'type' => 'string',
										),
										'state'     => array(
											'type' => 'string',
										),
										'postcode'  => array(
											'type' => 'string',
										),
										'country'   => array(
											'type' => 'string',
										),
									),
								),
								'details' => array(
									'type' => 'string',
								),
								'enabled' => array(
									'type' => 'boolean',
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Hydrate client settings
	 */
	public function hydrate_client_settings() {
		if ( ! wp_script_is( self::LOCAL_PICKUP_ADMIN_JS_HANDLE, 'enqueued' ) ) {
			// Only hydrate the settings if the script dependent on them is enqueued.
			return;
		}

		$locations = get_option( 'pickup_location_pickup_locations', array() );

		$formatted_pickup_locations = array();
		foreach ( $locations as $location ) {
			$formatted_pickup_locations[] = array(
				'name'    => $location['name'],
				'address' => $location['address'],
				'details' => $location['details'],
				'enabled' => wc_string_to_bool( $location['enabled'] ),
			);
		}

		$has_legacy_pickup = false;

		// Get all shipping zones.
		$shipping_zones              = \WC_Shipping_Zones::get_zones( 'admin' );
		$international_shipping_zone = new \WC_Shipping_Zone( 0 );

		// Loop through each shipping zone.
		foreach ( $shipping_zones as $shipping_zone ) {
			// Get all registered rates for this shipping zone.
			$shipping_methods = $shipping_zone['shipping_methods'];
			// Loop through each registered rate.
			foreach ( $shipping_methods as $shipping_method ) {
				if ( 'local_pickup' === $shipping_method->id && 'yes' === $shipping_method->enabled ) {
					$has_legacy_pickup = true;
					break 2;
				}
			}
		}

		foreach ( $international_shipping_zone->get_shipping_methods( true ) as $shipping_method ) {
			if ( 'local_pickup' === $shipping_method->id ) {
				$has_legacy_pickup = true;
				break;
			}
		}

		$settings = array(
			'pickupLocationSettings' => LocalPickupUtils::get_local_pickup_settings(),
			'pickupLocations'        => $formatted_pickup_locations,
			'readonlySettings'       => array(
				'hasLegacyPickup' => $has_legacy_pickup,
				'storeCountry'    => WC()->countries->get_base_country(),
				'storeState'      => WC()->countries->get_base_state(),
			),
		);

		wp_add_inline_script(
			self::LOCAL_PICKUP_ADMIN_JS_HANDLE,
			sprintf(
				'var hydratedScreenSettings = %s;',
				wp_json_encode( $settings )
			),
			'before'
		);
	}
	/**
	 * Load admin scripts.
	 */
	public function admin_scripts() {
		$this->asset_api->register_script( self::LOCAL_PICKUP_ADMIN_JS_HANDLE, 'assets/client/blocks/wc-shipping-method-pickup-location.js', array(), true );
	}

	/**
	 * Registers the Local Pickup shipping method used by the Checkout Block.
	 */
	public function register_local_pickup() {
		if ( CartCheckoutUtils::is_checkout_block_default() ) {
			$wc_instance = WC();
			if ( is_object( $wc_instance ) && method_exists( $wc_instance, 'shipping' ) && is_object( $wc_instance->shipping ) && method_exists( $wc_instance->shipping, 'register_shipping_method' ) ) {
				$wc_instance->shipping->register_shipping_method( new PickupLocation() );
			} else {
				wc_get_logger()->error( 'Error registering pickup location: WC()->shipping->register_shipping_method is not available', array( 'source' => 'shipping-controller' ) );
			}
		}
	}

	/**
	 * Declares the Pickup Location shipping method as a Local Pickup method for WooCommerce.
	 *
	 * @param array $methods Shipping method ids.
	 * @return array
	 */
	public function register_local_pickup_method( $methods ) {
		$methods[] = 'pickup_location';
		return $methods;
	}

	/**
	 * Hides the shipping address on the order confirmation page when local pickup is selected.
	 *
	 * @param array $pickup_methods Method ids.
	 * @return array
	 */
	public function hide_shipping_address_for_local_pickup( $pickup_methods ) {
		return array_merge( $pickup_methods, LocalPickupUtils::get_local_pickup_method_ids() );
	}

	/**
	 * Everytime we save or update local pickup settings, we flush the shipping
	 * transient group.
	 *
	 * @param array $settings The setting array we're saving.
	 * @return array $settings The setting array we're saving.
	 */
	public function flush_cache( $settings ) {
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		return $settings;
	}
	/**
	 * Filter the location used for taxes based on the chosen pickup location.
	 *
	 * @param array $address Location args.
	 * @return array
	 */
	public function filter_taxable_address( $address ) {

		if ( null === WC()->session ) {
			return $address;
		}
		// We only need to select from the first package, since pickup_location only supports a single package.
		$chosen_method          = current( WC()->session->get( 'chosen_shipping_methods', array() ) ) ?? '';
		$chosen_method_id       = explode( ':', $chosen_method )[0];
		$chosen_method_instance = explode( ':', $chosen_method )[1] ?? 0;

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		if ( $chosen_method_id && true === apply_filters( 'woocommerce_apply_base_tax_for_local_pickup', true ) && in_array( $chosen_method_id, LocalPickupUtils::get_local_pickup_method_ids(), true ) ) {
			$pickup_locations = get_option( 'pickup_location_pickup_locations', array() );
			$pickup_location  = $pickup_locations[ $chosen_method_instance ] ?? array();

			if ( isset( $pickup_location['address'], $pickup_location['address']['country'] ) && ! empty( $pickup_location['address']['country'] ) ) {
				$address = array(
					$pickup_locations[ $chosen_method_instance ]['address']['country'],
					$pickup_locations[ $chosen_method_instance ]['address']['state'],
					$pickup_locations[ $chosen_method_instance ]['address']['postcode'],
					$pickup_locations[ $chosen_method_instance ]['address']['city'],
				);
			}
		}

		return $address;
	}

	/**
	 * Local Pickup requires all packages to support local pickup. This is because the entire order must be picked up
	 * so that all packages get the same tax rates applied during checkout.
	 *
	 * If a shipping package does not support local pickup (e.g. if disabled by an extension), this filters the option
	 * out for all packages. This will in turn disable the "pickup" toggle in Block Checkout.
	 *
	 * @param array $packages Array of shipping packages.
	 * @return array
	 */
	public function filter_shipping_packages( $packages ) {
		// Check all packages for an instance of a collectable shipping method.
		$valid_packages = array_filter(
			$packages,
			function ( $package ) {
				$shipping_method_ids = ArrayUtil::select( $package['rates'] ?? array(), 'get_method_id', ArrayUtil::SELECT_BY_OBJECT_METHOD );
				return ! empty( array_intersect( LocalPickupUtils::get_local_pickup_method_ids(), $shipping_method_ids ) );
			}
		);

		// Remove pickup location from rates arrays if not all packages can be picked up or support local pickup.
		if ( count( $valid_packages ) !== count( $packages ) ) {
			$packages = array_map(
				function ( $package ) {
					if ( ! is_array( $package['rates'] ) ) {
						$package['rates'] = array();
						return $package;
					}
					$package['rates'] = array_filter(
						$package['rates'],
						function ( $rate ) {
							return ! in_array( $rate->get_method_id(), LocalPickupUtils::get_local_pickup_method_ids(), true );
						}
					);
					return $package;
				},
				$packages
			);
		}

		return $packages;
	}

	/**
	 * Remove shipping (i.e. delivery, not local pickup) if "Hide shipping costs until an address is entered" is enabled,
	 * and no address has been entered yet.
	 *
	 * Only applies to block checkout because pickup is chosen separately to shipping in that context.
	 *
	 * @param array $packages Array of shipping packages.
	 * @return array
	 */
	public function remove_shipping_if_no_address( $packages ) {
		if ( 'shortcode' === WC()->cart->cart_context ) {
			return $packages;
		}

		$shipping_cost_requires_address = wc_string_to_bool( get_option( 'woocommerce_shipping_cost_requires_address', 'no' ) );

		// Return early here for a small performance gain if we don't need to hide shipping costs until an address is entered.
		if ( ! $shipping_cost_requires_address ) {
			return $packages;
		}

		$customer = WC()->customer;

		if ( $customer instanceof WC_Customer && $customer->has_full_shipping_address() ) {
			return $packages;
		}

		return array_map(
			function ( $package ) {
				// Package rates is always an array due to a check in core.
				$package['rates'] = array_filter(
					$package['rates'],
					function ( $rate ) {
						return $rate instanceof WC_Shipping_Rate && in_array( $rate->get_method_id(), LocalPickupUtils::get_local_pickup_method_ids(), true );
					}
				);
				return $package;
			},
			$packages
		);
	}

	/**
	 * Track local pickup settings changes via Store API
	 *
	 * @param bool              $served Whether the request has already been served.
	 * @param \WP_REST_Response $result The response object.
	 * @param \WP_REST_Request  $request The request object.
	 * @return bool
	 */
	public function track_local_pickup( $served, $result, $request ) {
		if ( '/wp/v2/settings' !== $request->get_route() ) {
			return $served;
		}
		// Param name here comes from the show_in_rest['name'] value when registering the setting.
		if ( ! $request->get_param( 'pickup_location_settings' ) && ! $request->get_param( 'pickup_locations' ) ) {
			return $served;
		}

		$event_name = 'local_pickup_save_changes';

		$settings  = $request->get_param( 'pickup_location_settings' );
		$locations = $request->get_param( 'pickup_locations' );

		$data = array(
			'local_pickup_enabled'     => 'yes' === $settings['enabled'] ? true : false,
			'title'                    => __( 'Pickup', 'woocommerce' ) === $settings['title'],
			'price'                    => '' === $settings['cost'] ? true : false,
			'cost'                     => '' === $settings['cost'] ? 0 : $settings['cost'],
			'taxes'                    => $settings['tax_status'],
			'total_pickup_locations'   => count( $locations ),
			'pickup_locations_enabled' => count(
				array_filter(
					$locations,
					function ( $location ) {
						return $location['enabled']; }
				)
			),
		);

		WC_Tracks::record_event( $event_name, $data );

		return $served;
	}

	/**
	 * Check if legacy local pickup is activated in any of the shipping zones or in the Rest of the World zone.
	 *
	 * @since 8.8.0
	 *
	 * @return bool
	 */
	public static function is_legacy_local_pickup_active() {
		$rest_of_the_world                          = \WC_Shipping_Zones::get_zone_by( 'zone_id', 0 );
		$shipping_zones                             = \WC_Shipping_Zones::get_zones();
		$rest_of_the_world_data                     = $rest_of_the_world->get_data();
		$rest_of_the_world_data['shipping_methods'] = $rest_of_the_world->get_shipping_methods();
		array_unshift( $shipping_zones, $rest_of_the_world_data );

		foreach ( $shipping_zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'local_pickup' === $method->id && $method->is_enabled() ) {
					return true;
				}
			}
		}

		return false;
	}
}
