<?php

// This file is auto-generated and is for apps only. Bundles SHOULD NOT rely on its content.

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Config\Loader\ParamConfigurator as Param;

/**
 * This class provides array-shapes for configuring the services and bundles of an application.
 *
 * Services declared with the config() method below are autowired and autoconfigured by default.
 *
 * This is for apps only. Bundles SHOULD NOT use it.
 *
 * Example:
 *
 *     ```php
 *     // config/services.php
 *     namespace Symfony\Component\DependencyInjection\Loader\Configurator;
 *
 *     return App::config([
 *         'services' => [
 *             'App\\' => [
 *                 'resource' => '../src/',
 *             ],
 *         ],
 *     ]);
 *     ```
 *
 * @psalm-type ImportsConfig = list<string|array{
 *     resource: string,
 *     type?: string|null,
 *     ignore_errors?: bool|'not_found',
 * }>
 * @psalm-type ParametersConfig = array<string, scalar|\UnitEnum|array<scalar|\UnitEnum|array<mixed>|Param|null>|Param|null>
 * @psalm-type ArgumentsType = list<mixed>|array<string, mixed>
 * @psalm-type CallType = array<string, ArgumentsType>|array{0:string, 1?:ArgumentsType, 2?:bool}|array{method:string, arguments?:ArgumentsType, returns_clone?:bool}
 * @psalm-type TagsType = list<string|array<string, array<string, mixed>>> // arrays inside the list must have only one element, with the tag name as the key
 * @psalm-type CallbackType = string|array{0:string|ReferenceConfigurator,1:string}|\Closure|ReferenceConfigurator
 * @psalm-type DeprecationType = array{package: string, version: string, message?: string}
 * @psalm-type DefaultsType = array{
 *     public?: bool,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     autowire?: bool,
 *     autoconfigure?: bool,
 *     bind?: array<string, mixed>,
 * }
 * @psalm-type InstanceofType = array{
 *     shared?: bool,
 *     lazy?: bool|string,
 *     public?: bool,
 *     factory?: CallbackType,
 *     properties?: array<string, mixed>,
 *     configurator?: CallbackType,
 *     calls?: list<CallType>,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     autowire?: bool,
 *     bind?: array<string, mixed>,
 *     constructor?: string,
 * }
 * @psalm-type DefinitionType = array{
 *     class?: string,
 *     file?: string,
 *     parent?: string,
 *     shared?: bool,
 *     synthetic?: bool,
 *     lazy?: bool|string,
 *     public?: bool,
 *     abstract?: bool,
 *     deprecated?: DeprecationType,
 *     factory?: CallbackType,
 *     configurator?: CallbackType,
 *     arguments?: ArgumentsType,
 *     properties?: array<string, mixed>,
 *     calls?: list<CallType>,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     decorates?: string,
 *     decorates_tag?: string,
 *     decoration_inner_name?: string,
 *     decoration_priority?: int,
 *     decoration_on_invalid?: 'exception'|'ignore'|null,
 *     autowire?: bool,
 *     autoconfigure?: bool,
 *     bind?: array<string, mixed>,
 *     constructor?: string,
 *     from_callable?: CallbackType,
 * }
 * @psalm-type AliasType = string|array{
 *     alias: string,
 *     public?: bool,
 *     deprecated?: DeprecationType,
 * }
 * @psalm-type PrototypeType = array{
 *     resource: string,
 *     namespace?: string,
 *     exclude?: string|list<string>,
 *     parent?: string,
 *     shared?: bool,
 *     lazy?: bool|string,
 *     public?: bool,
 *     abstract?: bool,
 *     deprecated?: DeprecationType,
 *     factory?: CallbackType,
 *     arguments?: ArgumentsType,
 *     properties?: array<string, mixed>,
 *     configurator?: CallbackType,
 *     calls?: list<CallType>,
 *     tags?: TagsType,
 *     resource_tags?: TagsType,
 *     autowire?: bool,
 *     autoconfigure?: bool,
 *     bind?: array<string, mixed>,
 *     constructor?: string,
 * }
 * @psalm-type StackType = array{
 *     stack: list<DefinitionType|AliasType|PrototypeType|array<class-string, ArgumentsType|null>>,
 *     public?: bool,
 *     deprecated?: DeprecationType,
 *     decorates?: string,
 *     decorates_tag?: string,
 *     decoration_inner_name?: string,
 *     decoration_priority?: int,
 *     decoration_on_invalid?: 'exception'|'ignore'|null,
 * }
 * @psalm-type ServicesConfig = array{
 *     _defaults?: DefaultsType,
 *     _instanceof?: array<class-string, InstanceofType>,
 *     ...<string, DefinitionType|AliasType|PrototypeType|StackType|ArgumentsType|null>
 * }
 * @psalm-type ExtensionType = array<string, mixed>
 * @psalm-type RouterConfig = bool|array{
 *     enabled?: bool|Param, // Default: false
 *     resource?: scalar|Param|null, // Default: null
 *     type?: scalar|Param|null,
 *     default_uri?: scalar|Param|null, // The default URI used to generate URLs in a non-HTTP context. // Default: null
 *     http_port?: scalar|Param|null, // Default: 80
 *     https_port?: scalar|Param|null, // Default: 443
 *     strict_requirements?: scalar|Param|null, // set to true to throw an exception when a parameter does not match the requirements set to false to disable exceptions when a parameter does not match the requirements (and return null instead) set to null to disable parameter checks against requirements 'true' is the preferred configuration in development mode, while 'false' or 'null' might be preferred in production // Default: true
 *     utf8?: bool|Param, // Default: true
 *     ...<string, mixed>
 * }
 * @psalm-type CacheConfig = array{
 *     prefix_seed?: scalar|Param|null, // Used to namespace cache keys when using several apps with the same shared backend. // Default: "_%kernel.project_dir%.%kernel.container_class%"
 *     app?: scalar|Param|null, // App related cache pools configuration. Cannot be combined with "default_provider". // Default: "cache.adapter.filesystem"
 *     system?: scalar|Param|null, // System related cache pools configuration. // Default: "cache.adapter.system"
 *     directory?: scalar|Param|null, // Default: "%kernel.share_dir%/pools/app"
 *     default_provider?: scalar|Param|null, // DSN of the backend to use for "cache.app"; the adapter is deduced from it. Replaces "app", which cannot be set alongside it.
 *     default_psr6_provider?: scalar|Param|null,
 *     default_redis_provider?: scalar|Param|null, // Default: "redis://localhost"
 *     default_valkey_provider?: scalar|Param|null, // Default: "valkey://localhost"
 *     default_memcached_provider?: scalar|Param|null, // Default: "memcached://localhost"
 *     default_doctrine_dbal_provider?: scalar|Param|null, // Default: "database_connection"
 *     default_pdo_provider?: scalar|Param|null, // Default: null
 *     default_mongodb_provider?: scalar|Param|null, // Default: "mongodb://localhost/app"
 *     pools?: array<string, array{ // Default: []
 *         adapters?: Param|string|list<scalar|Param|null>,
 *         tags?: scalar|Param|null, // Default: null
 *         public?: bool|Param, // Default: false
 *         default_lifetime?: scalar|Param|null, // Default lifetime of the pool.
 *         provider?: scalar|Param|null, // Overwrite the setting from the default provider for this adapter.
 *         early_expiration_message_bus?: scalar|Param|null,
 *         clearer?: scalar|Param|null,
 *         marshaller?: scalar|Param|null, // The marshaller service to use for this pool.
 *     }>,
 * }
 * @psalm-type AssetConfig = bool|array{
 *     enabled?: bool|Param, // Default: true
 *     strict_mode?: bool|Param, // Throw an exception if an entry is missing from the manifest.json. // Default: false
 *     version_strategy?: scalar|Param|null, // Default: null
 *     version?: scalar|Param|null, // Default: null
 *     version_format?: scalar|Param|null, // Default: "%%s?%%s"
 *     json_manifest_path?: scalar|Param|null, // Default: null
 *     base_path?: scalar|Param|null, // Default: ""
 *     base_urls?: Param|string|list<scalar|Param|null>,
 *     packages?: array<string, array{ // Default: []
 *         strict_mode?: bool|Param, // Throw an exception if an entry is missing from the manifest.json. // Default: false
 *         version_strategy?: scalar|Param|null, // Default: null
 *         version?: scalar|Param|null,
 *         version_format?: scalar|Param|null, // Default: null
 *         json_manifest_path?: scalar|Param|null, // Default: null
 *         base_path?: scalar|Param|null, // Default: ""
 *         base_urls?: Param|string|list<scalar|Param|null>,
 *     }>,
 * }
 * @psalm-type SerializerConfig = bool|array{
 *     enabled?: bool|Param, // Default: true
 *     enable_attributes?: bool|Param, // Default: true
 *     name_converter?: scalar|Param|null,
 *     circular_reference_handler?: scalar|Param|null,
 *     max_depth_handler?: scalar|Param|null,
 *     mapping?: array{
 *         paths?: list<scalar|Param|null>,
 *     },
 *     default_context?: array<string, mixed>,
 *     named_serializers?: array<string, array{ // Default: []
 *         name_converter?: scalar|Param|null,
 *         default_context?: array<string, mixed>,
 *         include_built_in_normalizers?: bool|Param, // Whether to include the built-in normalizers // Default: true
 *         include_built_in_encoders?: bool|Param, // Whether to include the built-in encoders // Default: true
 *     }>,
 * }
 * @psalm-type ValidationConfig = bool|array{
 *     enabled?: bool|Param, // Default: true
 *     enable_attributes?: bool|Param, // Default: true
 *     static_method?: Param|string|list<scalar|Param|null>,
 *     translation_domain?: scalar|Param|null, // Default: "validators"
 *     email_validation_mode?: "html5"|"html5-allow-no-tld"|"strict"|Param, // Default: "html5"
 *     mapping?: array{
 *         paths?: list<scalar|Param|null>,
 *     },
 *     not_compromised_password?: bool|array{
 *         enabled?: bool|Param, // When disabled, compromised passwords will be accepted as valid. // Default: true
 *         endpoint?: scalar|Param|null, // API endpoint for the NotCompromisedPassword Validator. // Default: null
 *     },
 *     disable_translation?: bool|Param, // Default: false
 *     property_metadata_existence_check?: bool|Param, // When enabled, validateProperty() and validatePropertyValue() throw an exception if no metadata is found for the given property. // Default: false
 *     auto_mapping?: array<string, array{ // Default: []
 *         services?: list<scalar|Param|null>,
 *     }>,
 * }
 * @psalm-type TypeInfoConfig = bool|array{
 *     enabled?: bool|Param, // Default: true
 *     aliases?: array<string, scalar|Param|null>,
 * }
 * @psalm-type PropertyAccessConfig = bool|array{ // Property access configuration
 *     enabled?: bool|Param, // Default: true
 *     magic_call?: bool|Param, // Default: false
 *     magic_get?: bool|Param, // Default: true
 *     magic_set?: bool|Param, // Default: true
 *     throw_exception_on_invalid_index?: bool|Param, // Default: false
 *     throw_exception_on_invalid_property_path?: bool|Param, // Default: true
 *     wildcard_reads?: bool|Param, // Enables reading every element of a collection through a "[*]" wildcard. // Default: false
 * }
 * @psalm-type PropertyInfoConfig = bool|array{ // Property info configuration
 *     enabled?: bool|Param, // Default: true
 *     with_constructor_extractor?: bool|Param, // Registers the constructor extractor. // Default: true
 * }
 * @psalm-type FrameworkConfig = array{
 *     secret?: scalar|Param|null,
 *     http_method_override?: bool|Param, // Set true to enable support for the '_method' request parameter to determine the intended HTTP method on POST requests. // Default: false
 *     allowed_http_method_override?: null|list<string|Param>,
 *     trust_x_sendfile_type_header?: scalar|Param|null, // Set true to enable support for xsendfile in binary file responses. // Default: "%env(bool:default::SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER)%"
 *     ide?: scalar|Param|null, // Deprecated: Setting the "framework.ide.ide" configuration option is deprecated, use the "SYMFONY_IDE" env var instead. // Default: "%env(default::SYMFONY_IDE)%"
 *     test?: bool|Param,
 *     default_locale?: scalar|Param|null, // Default: "en"
 *     set_locale_from_accept_language?: bool|Param, // Whether to use the Accept-Language HTTP header to set the Request locale (only when the "_locale" request attribute is not passed). // Default: false
 *     set_content_language_from_locale?: bool|Param, // Whether to set the Content-Language HTTP header on the Response using the Request locale. // Default: false
 *     enabled_locales?: list<scalar|Param|null>,
 *     trusted_hosts?: Param|string|list<scalar|Param|null>,
 *     trusted_proxies?: mixed, // Default: ["%env(default::SYMFONY_TRUSTED_PROXIES)%"]
 *     trusted_headers?: Param|string|list<scalar|Param|null>,
 *     error_controller?: scalar|Param|null, // Default: "error_controller"
 *     handle_all_throwables?: bool|Param, // HttpKernel will handle all kinds of \Throwable. // Default: true
 *     csrf_protection?: bool|array{
 *         enabled?: scalar|Param|null, // Default: null
 *         stateless_token_ids?: list<scalar|Param|null>,
 *         check_header?: scalar|Param|null, // Whether to check the CSRF token in a header in addition to a cookie when using stateless protection. // Default: false
 *         cookie_name?: scalar|Param|null, // The name of the cookie to use when using stateless protection. // Default: "csrf-token"
 *     },
 *     form?: bool|array{ // Form configuration
 *         enabled?: bool|Param, // Default: true
 *         csrf_protection?: bool|array{
 *             enabled?: scalar|Param|null, // Default: null
 *             token_id?: scalar|Param|null, // Default: null
 *             field_name?: scalar|Param|null, // Default: "_token"
 *             field_attr?: array<string, scalar|Param|null>,
 *         },
 *     },
 *     http_cache?: bool|array{ // HTTP cache configuration
 *         enabled?: bool|Param, // Default: false
 *         debug?: bool|Param, // Default: "%kernel.debug%"
 *         trace_level?: "none"|"short"|"full"|Param,
 *         trace_header?: scalar|Param|null,
 *         cache_status?: scalar|Param|null, // Enables the RFC 9211 "Cache-Status" response header and names this cache in it, e.g. "Symfony". No header is added when null.
 *         default_ttl?: int|Param,
 *         private_headers?: list<scalar|Param|null>,
 *         skip_response_headers?: list<scalar|Param|null>,
 *         allow_reload?: bool|Param,
 *         allow_revalidate?: bool|Param,
 *         stale_while_revalidate?: int|Param,
 *         stale_if_error?: int|Param,
 *         terminate_on_cache_hit?: bool|Param, // Deprecated: Setting the "framework.http_cache.terminate_on_cache_hit.terminate_on_cache_hit" configuration option is deprecated. It will be removed in version 9.0.
 *     },
 *     esi?: bool|array{ // ESI configuration
 *         enabled?: bool|Param, // Default: false
 *     },
 *     ssi?: bool|array{ // SSI configuration
 *         enabled?: bool|Param, // Default: false
 *     },
 *     fragments?: bool|array{ // Fragments configuration
 *         enabled?: bool|Param, // Default: false
 *         hinclude_default_template?: scalar|Param|null, // Deprecated: Setting the "framework.fragments.hinclude_default_template.hinclude_default_template" configuration option is deprecated. It will be removed in version 9.0. // Default: null
 *         path?: scalar|Param|null, // Default: "/_fragment"
 *     },
 *     uri_signer?: array{ // URI signer configuration
 *         expiration?: int|Param, // Default expiration of signed URIs, in seconds. // Default: null
 *     },
 *     profiler?: bool|array{ // Profiler configuration
 *         enabled?: bool|Param, // Default: false
 *         collect?: bool|Param, // Default: true
 *         collect_parameter?: scalar|Param|null, // The name of the parameter to use to enable or disable collection on a per request basis. // Default: null
 *         only_exceptions?: bool|Param, // Default: false
 *         only_main_requests?: bool|Param, // Default: false
 *         excluded_paths?: Param|string|list<scalar|Param|null>,
 *         excluded_http_codes?: Param|int|string|list<Param|string|list<scalar|Param|null>>,
 *         dsn?: scalar|Param|null, // Default: "file:%kernel.cache_dir%/profiler"
 *         collect_serializer_data?: true|Param, // Deprecated: Setting the "framework.profiler.collect_serializer_data.collect_serializer_data" configuration option is deprecated. It will be removed in version 9.0. // Default: true
 *     },
 *     workflows?: mixed,
 *     router?: RouterConfig,
 *     assets?: AssetConfig,
 *     asset_mapper?: mixed,
 *     translator?: mixed,
 *     validation?: ValidationConfig,
 *     serializer?: SerializerConfig,
 *     property_access?: PropertyAccessConfig,
 *     type_info?: TypeInfoConfig,
 *     property_info?: PropertyInfoConfig,
 *     cache?: CacheConfig,
 *     web_link?: mixed,
 *     lock?: mixed,
 *     semaphore?: mixed,
 *     messenger?: mixed,
 *     scheduler?: mixed,
 *     http_client?: mixed,
 *     mailer?: mixed,
 *     notifier?: mixed,
 *     rate_limiter?: mixed,
 *     uid?: mixed,
 *     html_sanitizer?: mixed,
 *     webhook?: mixed,
 *     remote_event?: mixed,
 *     json_streamer?: mixed,
 *     session?: bool|array{ // Session configuration
 *         enabled?: bool|Param, // Default: false
 *         storage_factory_id?: scalar|Param|null, // Default: "session.storage.factory.native"
 *         handler_id?: scalar|Param|null, // Defaults to using the native session handler, or to the native *file* session handler if "save_path" is not null.
 *         name?: scalar|Param|null,
 *         cookie_lifetime?: scalar|Param|null,
 *         cookie_path?: scalar|Param|null,
 *         cookie_domain?: scalar|Param|null,
 *         cookie_secure?: true|false|"auto"|Param, // Default: "auto"
 *         cookie_httponly?: bool|Param, // Default: true
 *         cookie_samesite?: null|"lax"|"strict"|"none"|Param, // Default: "lax"
 *         use_cookies?: bool|Param,
 *         gc_divisor?: scalar|Param|null,
 *         gc_probability?: scalar|Param|null,
 *         gc_maxlifetime?: scalar|Param|null,
 *         save_path?: scalar|Param|null, // Defaults to "%kernel.cache_dir%/sessions" if the "handler_id" option is not null.
 *         metadata_update_threshold?: int|Param, // Seconds to wait between 2 session metadata updates. // Default: 0
 *     },
 *     request?: bool|array{ // Request configuration
 *         enabled?: bool|Param, // Default: false
 *         formats?: array<string, Param|string|list<scalar|Param|null>>,
 *     },
 *     php_errors?: array{ // PHP errors handling configuration
 *         log?: mixed, // Use the application logger instead of the PHP logger for logging PHP errors. // Default: true
 *         throw?: bool|Param, // Throw PHP errors as \ErrorException instances. // Default: true
 *     },
 *     exceptions?: array<string, array{ // Default: []
 *         log_level?: scalar|Param|null, // The level of log message. Null to let Symfony decide. // Default: null
 *         status_code?: scalar|Param|null, // The status code of the response. Null or 0 to let Symfony decide. // Default: null
 *         log_channel?: scalar|Param|null, // The channel of log message. Null to let Symfony decide. // Default: null
 *     }>,
 *     disallow_search_engine_index?: bool|Param, // Enabled by default when debug is enabled. // Default: true
 *     secrets?: bool|array{
 *         enabled?: bool|Param, // Default: true
 *         vault_directory?: scalar|Param|null, // Default: "%kernel.project_dir%/config/secrets/%kernel.runtime_environment%"
 *         local_dotenv_file?: scalar|Param|null, // Default: "%kernel.project_dir%/.env.%kernel.environment%.local"
 *         decryption_env_var?: scalar|Param|null, // Default: "base64:default::SYMFONY_DECRYPTION_SECRET"
 *     },
 * }
 * @psalm-type TwigConfig = array{
 *     form_themes?: list<scalar|Param|null>,
 *     globals?: array<string, array{ // Default: []
 *         id?: scalar|Param|null,
 *         type?: scalar|Param|null,
 *         value?: mixed,
 *         ...<string, mixed>
 *     }>,
 *     autoescape_service?: scalar|Param|null, // Default: null
 *     autoescape_service_method?: scalar|Param|null, // Default: null
 *     cache?: scalar|Param|null, // Default: true
 *     charset?: scalar|Param|null, // Default: "%kernel.charset%"
 *     debug?: bool|Param, // Default: "%kernel.debug%"
 *     strict_variables?: bool|Param, // Default: "%kernel.debug%"
 *     auto_reload?: scalar|Param|null,
 *     optimizations?: int|Param,
 *     default_path?: scalar|Param|null, // The default path used to load templates. // Default: "%kernel.project_dir%/templates"
 *     file_name_pattern?: Param|string|list<scalar|Param|null>,
 *     paths?: array<string, mixed>,
 *     date?: array{ // The default format options used by the date filter.
 *         format?: scalar|Param|null, // Default: "F j, Y H:i"
 *         interval_format?: scalar|Param|null, // Default: "%d days"
 *         timezone?: scalar|Param|null, // The timezone used when formatting dates, when set to null, the timezone returned by date_default_timezone_get() is used. // Default: null
 *     },
 *     number_format?: array{ // The default format options for the number_format filter.
 *         decimals?: int|Param, // Default: 0
 *         decimal_point?: scalar|Param|null, // Default: "."
 *         thousands_separator?: scalar|Param|null, // Default: ","
 *     },
 *     mailer?: array{
 *         html_to_text_converter?: scalar|Param|null, // A service implementing the "Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface". // Default: null
 *     },
 * }
 * @psalm-type TwigExtraConfig = array{
 *     cache?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     html?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     markdown?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     intl?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     cssinliner?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     inky?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     string?: bool|array{
 *         enabled?: bool|Param, // Default: false
 *     },
 *     commonmark?: array{
 *         renderer?: array{ // Array of options for rendering HTML.
 *             block_separator?: scalar|Param|null,
 *             inner_separator?: scalar|Param|null,
 *             soft_break?: scalar|Param|null,
 *         },
 *         html_input?: "strip"|"allow"|"escape"|Param, // How to handle HTML input.
 *         allow_unsafe_links?: bool|Param, // Remove risky link and image URLs by setting this to false. // Default: true
 *         max_nesting_level?: int|Param, // The maximum nesting level for blocks. // Default: 9223372036854775807
 *         max_delimiters_per_line?: int|Param, // The maximum number of strong/emphasis delimiters per line. // Default: 9223372036854775807
 *         slug_normalizer?: array{ // Array of options for configuring how URL-safe slugs are created.
 *             instance?: mixed,
 *             max_length?: int|Param, // Default: 255
 *             unique?: mixed,
 *         },
 *         commonmark?: array{ // Array of options for configuring the CommonMark core extension.
 *             enable_em?: bool|Param, // Default: true
 *             enable_strong?: bool|Param, // Default: true
 *             use_asterisk?: bool|Param, // Default: true
 *             use_underscore?: bool|Param, // Default: true
 *             unordered_list_markers?: list<scalar|Param|null>,
 *         },
 *         ...<string, mixed>
 *     },
 * }
 * @psalm-type SecurityConfig = array{
 *     access_denied_url?: scalar|Param|null, // Default: null
 *     recent_authentication_lifetime?: int|Param, // Number of seconds an interactive authentication keeps granting IS_AUTHENTICATED_RECENTLY. Use it to make sensitive actions require the user to authenticate again. // Default: 7200
 *     very_recent_authentication_lifetime?: int|Param, // Number of seconds an interactive authentication keeps granting IS_AUTHENTICATED_VERY_RECENTLY, a stricter bar than IS_AUTHENTICATED_RECENTLY for the most sensitive actions. // Default: 300
 *     session_fixation_strategy?: "none"|"migrate"|"invalidate"|Param, // Default: "migrate"
 *     expose_security_errors?: \Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel::None|\Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel::AccountStatus|\Symfony\Component\Security\Http\Authentication\ExposeSecurityLevel::All|Param, // Default: "none"
 *     erase_credentials?: bool|Param, // Deprecated: Setting the "security.erase_credentials.erase_credentials" configuration option is deprecated. It will be removed in Symfony 9.0, as the "eraseCredentials()" method was removed in Symfony 8.0. // Default: true
 *     access_decision_manager?: array{
 *         strategy?: "affirmative"|"consensus"|"unanimous"|"priority"|Param,
 *         service?: scalar|Param|null,
 *         strategy_service?: scalar|Param|null,
 *         allow_if_all_abstain?: bool|Param, // Default: false
 *         allow_if_equal_granted_denied?: bool|Param, // Default: true
 *     },
 *     password_hashers?: array<string, Param|string|array{ // Default: []
 *         algorithm?: scalar|Param|null,
 *         migrate_from?: Param|string|list<scalar|Param|null>,
 *         hash_algorithm?: scalar|Param|null, // Name of hashing algorithm for PBKDF2 (i.e. sha256, sha512, etc..) See hash_algos() for a list of supported algorithms. // Default: "sha512"
 *         key_length?: scalar|Param|null, // Default: 40
 *         ignore_case?: bool|Param, // Default: false
 *         encode_as_base64?: bool|Param, // Default: true
 *         iterations?: scalar|Param|null, // Default: 5000
 *         cost?: int|Param, // Default: null
 *         memory_cost?: scalar|Param|null, // Default: null
 *         time_cost?: scalar|Param|null, // Default: null
 *         id?: scalar|Param|null,
 *     }>,
 *     providers?: array<string, array{ // Default: []
 *         id?: scalar|Param|null,
 *         chain?: array{
 *             providers?: Param|string|list<scalar|Param|null>,
 *         },
 *         memory?: array{
 *             users?: array<string, array{ // Default: []
 *                 password?: scalar|Param|null, // Default: null
 *                 roles?: Param|string|list<scalar|Param|null>,
 *             }>,
 *         },
 *         ldap?: array{
 *             service?: scalar|Param|null,
 *             base_dn?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: null
 *             search_password?: scalar|Param|null, // Default: null
 *             extra_fields?: list<scalar|Param|null>,
 *             default_roles?: Param|string|list<scalar|Param|null>,
 *             role_fetcher?: scalar|Param|null, // Default: null
 *             uid_key?: scalar|Param|null, // Default: "sAMAccountName"
 *             filter?: scalar|Param|null, // Default: "({uid_key}={user_identifier})"
 *             password_attribute?: scalar|Param|null, // Default: null
 *         },
 *         oidc?: array{
 *             enabled?: bool|Param, // Internal marker; the OIDC provider has no configuration options. // Default: true
 *             ...<string, mixed>
 *         },
 *     }>,
 *     firewalls?: array<string, array{ // Default: []
 *         pattern?: scalar|Param|null,
 *         host?: scalar|Param|null,
 *         methods?: Param|string|list<scalar|Param|null>,
 *         security?: bool|Param, // Default: true
 *         user_checker?: scalar|Param|null, // The UserChecker to use when authenticating users in this firewall. // Default: "security.user_checker"
 *         user_checker_on_refresh?: bool|Param, // Whether to run this firewall's UserChecker again when the user is refreshed from the session, so that an account disabled during the session is rejected on the next request. It then runs on every request of this firewall, so enable it only if that checker is safe to call that often. // Default: false
 *         request_matcher?: scalar|Param|null,
 *         access_denied_url?: scalar|Param|null,
 *         access_denied_handler?: scalar|Param|null,
 *         entry_point?: scalar|Param|null, // An enabled authenticator name or a service id that implements "Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface".
 *         re_authentication_entry_point?: scalar|Param|null, // Service id implementing "Symfony\Component\Security\Http\EntryPoint\ReAuthenticationEntryPointInterface", asking an already authenticated user to prove possession of their credentials again when IS_AUTHENTICATED_RECENTLY or IS_AUTHENTICATED_VERY_RECENTLY is denied. Defaults to the firewall entry point when that one implements it.
 *         provider?: scalar|Param|null,
 *         stateless?: bool|Param, // Default: false
 *         lazy?: bool|Param, // Default: false
 *         context?: scalar|Param|null,
 *         logout?: array{
 *             enable_csrf?: bool|Param|null, // Default: null
 *             csrf_token_id?: scalar|Param|null, // Default: "logout"
 *             csrf_parameter?: scalar|Param|null, // Default: "_csrf_token"
 *             csrf_token_manager?: scalar|Param|null,
 *             path?: scalar|Param|null, // Default: "/logout"
 *             target?: scalar|Param|null, // Default: "/"
 *             invalidate_session?: bool|Param, // Default: true
 *             clear_site_data?: Param|string|list<"*"|"cache"|"cookies"|"storage"|"clientHints"|"executionContexts"|"prefetchCache"|"prerenderCache"|Param>,
 *             delete_cookies?: Param|string|array<string, array{ // Default: []
 *                 path?: scalar|Param|null, // Default: null
 *                 domain?: scalar|Param|null, // Default: null
 *                 secure?: scalar|Param|null, // Default: false
 *                 samesite?: scalar|Param|null, // Default: null
 *                 partitioned?: scalar|Param|null, // Default: false
 *             }>,
 *         },
 *         switch_user?: array{
 *             provider?: scalar|Param|null,
 *             parameter?: scalar|Param|null, // Default: "_switch_user"
 *             role?: scalar|Param|null, // Default: "ROLE_ALLOWED_TO_SWITCH"
 *             target_route?: scalar|Param|null, // Default: null
 *             path?: scalar|Param|null, // Restrict user switching to this path (a path or route name). Declaring the route POST-only is up to the application. The parameter is no longer read from the request headers in this mode. // Default: null
 *             enable_csrf?: bool|Param|null, // Default: null
 *             csrf_token_id?: scalar|Param|null, // Default: "switch_user"
 *             csrf_parameter?: scalar|Param|null, // Default: "_csrf_token"
 *             csrf_token_manager?: scalar|Param|null,
 *         },
 *         required_badges?: list<scalar|Param|null>,
 *         custom_authenticators?: list<scalar|Param|null>,
 *         login_throttling?: array{
 *             limiter?: scalar|Param|null, // A service id implementing "Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface".
 *             max_attempts?: int|Param, // Default: 5
 *             interval?: scalar|Param|null, // Default: "1 minute"
 *             lock_factory?: scalar|Param|null, // The service ID of the lock factory used by the login rate limiter ("auto" to use the default one when the Lock component is configured, or null to disable locking). // Default: "auto"
 *             cache_pool?: string|Param, // The cache pool to use for storing the limiter state // Default: "cache.rate_limiter"
 *             storage_service?: string|Param, // The service ID of a custom storage implementation, this precedes any configured "cache_pool" // Default: null
 *         },
 *         x509?: array{
 *             provider?: scalar|Param|null,
 *             user?: scalar|Param|null, // Default: "SSL_CLIENT_S_DN_Email"
 *             credentials?: scalar|Param|null, // Default: "SSL_CLIENT_S_DN"
 *             user_identifier?: scalar|Param|null, // Default: "emailAddress"
 *         },
 *         remote_user?: array{
 *             provider?: scalar|Param|null,
 *             user?: scalar|Param|null, // Default: "REMOTE_USER"
 *         },
 *         login_link?: array{
 *             check_route?: scalar|Param|null, // Route that will validate the login link - e.g. "app_login_link_verify".
 *             check_post_only?: scalar|Param|null, // If true, only HTTP POST requests to "check_route" will be handled by the authenticator. // Default: false
 *             signature_properties?: list<scalar|Param|null>,
 *             lifetime?: int|Param, // The lifetime of the login link in seconds. // Default: 600
 *             max_uses?: int|Param, // Max number of times a login link can be used - null means unlimited within lifetime. // Default: null
 *             used_link_cache?: scalar|Param|null, // Cache service id used to expired links of max_uses is set.
 *             success_handler?: scalar|Param|null, // A service id that implements Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface.
 *             failure_handler?: scalar|Param|null, // A service id that implements Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface.
 *             provider?: scalar|Param|null, // The user provider to load users from.
 *             secret?: scalar|Param|null, // Default: "%kernel.secret%"
 *             always_use_default_target_path?: bool|Param, // Default: false
 *             default_target_path?: scalar|Param|null, // Default: "/"
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             target_path_parameter?: scalar|Param|null, // Default: "_target_path"
 *             use_referer?: bool|Param, // Default: false
 *             failure_path?: scalar|Param|null, // Default: null
 *             failure_forward?: bool|Param, // Default: false
 *             failure_path_parameter?: scalar|Param|null, // Default: "_failure_path"
 *         },
 *         oidc_login?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Deprecated: Setting the "security.firewalls..oidc_login.remember_me.remember_me" configuration option has no effect and is deprecated. It will be removed in Symfony 9.0. // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // The firewall path where the OIDC provider redirects after authentication. Must match a redirect URI registered with the provider. A route is declared for this path by the "security.authenticator.oidc_login.route_loader" service, which the application must import (see the OIDC login documentation), as it does for the logout routes. A route name is accepted too, in which case no route is declared for it. // Default: "/oidc/callback"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             always_use_default_target_path?: bool|Param, // Default: false
 *             default_target_path?: scalar|Param|null, // Default: "/"
 *             target_path_parameter?: scalar|Param|null, // Default: "_target_path"
 *             use_referer?: bool|Param, // Default: false
 *             failure_path?: scalar|Param|null, // Default: null
 *             failure_forward?: bool|Param, // Default: false
 *             failure_path_parameter?: scalar|Param|null, // Default: "_failure_path"
 *             provider_uri?: scalar|Param|null, // The OIDC Issuer URL (e.g. "https://accounts.example.com"). Used for .well-known/openid-configuration discovery.
 *             http_client?: scalar|Param|null, // The id of the HttpClient service every call to the provider is made with: discovery, JWKS, token and UserInfo endpoints. Defaults to "http_client". A scoped client must scope every host the provider announces, not only the issuer. // Default: null
 *             client_id?: scalar|Param|null, // The OIDC client identifier.
 *             client_authentication?: Param|string|array{ // How the client authenticates at the token endpoint, which RFC 7591, Section 2 names in its "token_endpoint_auth_method" metadata. Set the method Symfony ships with its parameters, or the id of a service implementing "Symfony\Component\Security\Http\OAuth2\ClientAuthentication\ClientAuthenticationInterface" for a scheme it does not. Exactly one of them.
 *                 client_secret_basic?: scalar|Param|null, // Send the client secret as HTTP Basic credentials, the "client_secret_basic" method of RFC 6749, Section 2.3.1, which the RFC recommends. Takes the client secret.
 *                 client_secret_post?: scalar|Param|null, // Send the client secret in the body of the token request, the "client_secret_post" method of RFC 6749, Section 2.3.1. Takes the client secret. Use it for the providers that support nothing else.
 *                 none?: bool|Param, // Declare a public client (a SPA, a mobile or a native application), which holds no secret and relies on PKCE to protect the code exchange. It can disable neither PKCE nor the ID token signature check.
 *                 client_secret_jwt?: Param|string|array{ // Authenticate with a JWT assertion keyed with the client secret, the "client_secret_jwt" method of OIDC Core 1.0, Section 9. The secret keys an HMAC and is never sent, but the provider holds it too and could sign an assertion in the name of the client: prefer "private_key_jwt", which nobody but the client can sign. Takes the client secret, or a mapping to also set "algorithm" and "lifetime".
 *                     secret?: scalar|Param|null, // The client secret, whose octets key the HMAC. It must be at least as long as the digest the algorithm produces, which RFC 7518, Section 3.2 requires and the algorithm itself checks: 32 bytes for "HS256", 48 for "HS384", 64 for "HS512".
 *                     algorithm?: "HS256"|"HS384"|"HS512"|Param, // The MAC algorithm the assertion is signed with, which must be one your provider announces in "token_endpoint_auth_signing_alg_values_supported". // Default: "HS256"
 *                     lifetime?: int|Param, // How long an assertion is valid, in seconds. It is built for one request and sent right away, so keep it short: it is the window a provider that does not track the "jti" would accept a captured assertion in. // Default: 60
 *                 },
 *                 private_key_jwt?: Param|string|array{ // Authenticate with a JWT assertion signed with the private key of the client, the "private_key_jwt" method of OIDC Core 1.0, Section 9, and the one FAPI 2.0 asks for. The provider only holds the public half, registered as the client "jwks" or fetched from its "jwks_uri", so it learns nothing it could authenticate as the client with.
 *                     key?: scalar|Param|null, // JSON-encoded JWK of the private key the assertion is signed with. Give it a "kid" when the client publishes several keys, so that the provider knows which one verifies the signature without trying them all.
 *                     algorithm?: "RS256"|"RS384"|"RS512"|"ES256"|"ES384"|"ES512"|"PS256"|"PS384"|"PS512"|Param, // The signature algorithm the assertion is signed with, which must be one your provider announces in "token_endpoint_auth_signing_alg_values_supported". FAPI 2.0 asks for "PS256" or "ES256". // Default: "RS256"
 *                     lifetime?: int|Param, // How long an assertion is valid, in seconds. It is built for one request and sent right away, so keep it short: it is the window a provider that does not track the "jti" would accept a captured assertion in. // Default: 60
 *                 },
 *                 id?: scalar|Param|null, // The id of a service implementing "ClientAuthenticationInterface", for a scheme Symfony does not ship, such as the "tls_client_auth" of RFC 8705, Section 2. The method it reports is only known once it is built, so the rules a public client cannot bend are then checked on the first request to this firewall instead of while the container compiles.
 *             },
 *             scope?: list<scalar|Param|null>,
 *             start_path?: scalar|Param|null, // The path where the route loader declares a route that starts the flow by redirecting to the provider; link to it e.g. from the login page of a firewall offering several ways to log in. A route name is accepted too, in which case no route is declared for it. // Default: "/oidc/start"
 *             discovery_cache_ttl?: int|Param, // TTL in seconds for caching the OIDC discovery configuration, and for the provider JWKS when it advertises no cache lifetime itself. // Default: 3600
 *             allowed_time_drift?: int|Param, // Allowed clock skew in seconds when validating ID token time claims. // Default: 0
 *             user_data_source?: "userinfo"|"id_token"|Param, // Where the user claims are read from: "userinfo" (default) fetches them from the UserInfo endpoint; "id_token" reads them from the validated ID token instead, for providers that put the requested claims there, some of which expose no UserInfo endpoint at all, which is then not required to be announced. // Default: "userinfo"
 *             user_identifier_claim?: scalar|Param|null, // The claim the user identifier is read from. "sub" (default) is the only claim OIDC guarantees stable and unique for the user. Only pick another claim, e.g. "email", when the provider guarantees its value unique, verified and stable too: whoever controls the value of that claim at the provider owns the matching account here. // Default: "sub"
 *             id_token_signature?: array{
 *                 required?: bool|Param, // When true (default), the ID token signature is verified against the provider JWKS. Setting it to false decodes the ID token without verifying it, which OIDC Core 1.0, Section 3.1.3.7, item 6 only allows because the token comes from the token endpoint over TLS: it is then only as safe as the TLS verification of the HTTP client used for that request, so never turn it off with a client configured with "verify_peer: false" or "verify_host: false", nor behind a TLS-terminating proxy. A public client, whose "client_authentication" reports the "none" method, cannot turn it off at all. // Default: true
 *                 algorithms?: list<scalar|Param|null>,
 *                 enforce_key_usage_verification?: bool|Param, // When enabled (default), only keys explicitly designated for signature (via "use":"sig" or a "key_ops" entry containing "sign"/"verify") are accepted. When disabled, keys without any usage designation are also accepted; keys explicitly restricted to encryption are still rejected. // Default: true
 *             },
 *             pkce?: array{
 *                 enabled?: bool|Param, // Whether to use PKCE (Proof Key for Code Exchange, RFC 7636), which any current provider should support; only disable it for one that rejects the "code_challenge" parameter. A public client, whose "client_authentication" reports the "none" method, cannot disable it at all. // Default: true
 *                 method?: "S256"|"plain"|Param, // The PKCE code challenge method. RFC 7636, Section 4.2 mandates "S256" for every client able to compute it, so only ever pick "plain" for a provider that supports nothing else. // Default: "S256"
 *             },
 *             max_age?: int|Param, // Maximum elapsed seconds since the end-user authentication, sent as the "max_age" authorization parameter; the ID token must then carry an "auth_time" claim, which is checked against this value, "allowed_time_drift" included.
 *             authorization_params?: array<string, scalar|Param|null>,
 *             refresh_access_token?: bool|array{ // Renew the access token with the refresh token grant of RFC 6749, Section 6, so that it stays usable to call an API on behalf of the logged-in user. The provider only issues a refresh token when it was asked for one, e.g. with the "offline_access" scope, and the renewal needs the "expires_in" it is optional for the provider to report. Whether this is enabled or not, the tokens are held as the "oidc_refresh_token", "oidc_access_token" and "oidc_access_token_expires_at" attributes of the security token, and the "security.authenticator.oidc_login.token_refresher.<firewall>" service renews them on demand. A provider rotating refresh tokens expects the previous one never to be replayed, which a session handler locking the session guarantees, and the default one does.
 *                 enabled?: bool|Param, // Default: false
 *                 leeway?: int|Param, // How many seconds before its expiry the access token is renewed, so that one handed to a call in flight does not expire on the way. // Default: 30
 *             },
 *             enable_end_session?: bool|Param, // Enable RP-Initiated Logout via the OIDC end_session_endpoint. // Default: false
 *             post_logout_redirect_path?: scalar|Param|null, // Path or route to redirect to after OIDC logout. // Default: "/"
 *         },
 *         form_login?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Deprecated: Setting the "security.firewalls..form_login.remember_me.remember_me" configuration option has no effect and is deprecated. It will be removed in Symfony 9.0. // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_parameter?: scalar|Param|null, // Default: "_username"
 *             password_parameter?: scalar|Param|null, // Default: "_password"
 *             csrf_parameter?: scalar|Param|null, // Default: "_csrf_token"
 *             csrf_token_id?: scalar|Param|null, // Default: "authenticate"
 *             enable_csrf?: bool|Param, // Default: false
 *             post_only?: bool|Param, // Default: true
 *             form_only?: bool|Param, // Default: false
 *             always_use_default_target_path?: bool|Param, // Default: false
 *             default_target_path?: scalar|Param|null, // Default: "/"
 *             target_path_parameter?: scalar|Param|null, // Default: "_target_path"
 *             use_referer?: bool|Param, // Default: false
 *             failure_path?: scalar|Param|null, // Default: null
 *             failure_forward?: bool|Param, // Default: false
 *             failure_path_parameter?: scalar|Param|null, // Default: "_failure_path"
 *         },
 *         form_login_ldap?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Deprecated: Setting the "security.firewalls..form_login_ldap.remember_me.remember_me" configuration option has no effect and is deprecated. It will be removed in Symfony 9.0. // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_parameter?: scalar|Param|null, // Default: "_username"
 *             password_parameter?: scalar|Param|null, // Default: "_password"
 *             csrf_parameter?: scalar|Param|null, // Default: "_csrf_token"
 *             csrf_token_id?: scalar|Param|null, // Default: "authenticate"
 *             enable_csrf?: bool|Param, // Default: false
 *             post_only?: bool|Param, // Default: true
 *             form_only?: bool|Param, // Default: false
 *             always_use_default_target_path?: bool|Param, // Default: false
 *             default_target_path?: scalar|Param|null, // Default: "/"
 *             target_path_parameter?: scalar|Param|null, // Default: "_target_path"
 *             use_referer?: bool|Param, // Default: false
 *             failure_path?: scalar|Param|null, // Default: null
 *             failure_forward?: bool|Param, // Default: false
 *             failure_path_parameter?: scalar|Param|null, // Default: "_failure_path"
 *             service?: scalar|Param|null, // Default: "ldap"
 *             dn_string?: scalar|Param|null, // Default: "{user_identifier}"
 *             query_string?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: ""
 *             search_password?: scalar|Param|null, // Default: ""
 *             ldap_users_only?: bool|Param, // Only bind users of class "Symfony\Component\Ldap\Security\LdapUser" against the LDAP server, and leave any other user to the regular password checker. // Default: false
 *         },
 *         json_login?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Deprecated: Setting the "security.firewalls..json_login.remember_me.remember_me" configuration option has no effect and is deprecated. It will be removed in Symfony 9.0. // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_path?: scalar|Param|null, // Default: "username"
 *             password_path?: scalar|Param|null, // Default: "password"
 *         },
 *         json_login_ldap?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Deprecated: Setting the "security.firewalls..json_login_ldap.remember_me.remember_me" configuration option has no effect and is deprecated. It will be removed in Symfony 9.0. // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             check_path?: scalar|Param|null, // Default: "/login_check"
 *             use_forward?: bool|Param, // Default: false
 *             login_path?: scalar|Param|null, // Default: "/login"
 *             username_path?: scalar|Param|null, // Default: "username"
 *             password_path?: scalar|Param|null, // Default: "password"
 *             service?: scalar|Param|null, // Default: "ldap"
 *             dn_string?: scalar|Param|null, // Default: "{user_identifier}"
 *             query_string?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: ""
 *             search_password?: scalar|Param|null, // Default: ""
 *             ldap_users_only?: bool|Param, // Only bind users of class "Symfony\Component\Ldap\Security\LdapUser" against the LDAP server, and leave any other user to the regular password checker. // Default: false
 *         },
 *         access_token?: array{
 *             provider?: scalar|Param|null,
 *             remember_me?: bool|Param, // Deprecated: Setting the "security.firewalls..access_token.remember_me.remember_me" configuration option has no effect and is deprecated. It will be removed in Symfony 9.0. // Default: true
 *             success_handler?: scalar|Param|null,
 *             failure_handler?: scalar|Param|null,
 *             realm?: scalar|Param|null, // Default: null
 *             token_extractors?: Param|string|list<scalar|Param|null>,
 *             token_handler?: Param|string|array{
 *                 id?: scalar|Param|null,
 *                 oidc_user_info?: Param|string|array{
 *                     base_uri?: scalar|Param|null, // Base URI of the userinfo endpoint on the OIDC server, or the OIDC server URI to use the discovery (require "discovery" to be configured).
 *                     discovery?: array{ // Enable the OIDC discovery.
 *                         cache?: array{
 *                             id?: scalar|Param|null, // Cache service id to use to cache the OIDC discovery configuration.
 *                         },
 *                     },
 *                     claim?: scalar|Param|null, // Claim which contains the user identifier (e.g. sub, email, etc.). // Default: "sub"
 *                     client?: scalar|Param|null, // HttpClient service id to use to call the OIDC server.
 *                 },
 *                 oidc?: array{
 *                     discovery?: array{ // Enable the OIDC discovery.
 *                         base_uri?: Param|string|list<scalar|Param|null>,
 *                         cache?: array{
 *                             id?: scalar|Param|null, // Cache service id to use to cache the OIDC discovery configuration.
 *                         },
 *                         enforce_key_usage_verification?: bool|Param, // When enabled (default), only keys explicitly designated for signature (via "use":"sig" or a "key_ops" entry containing "sign"/"verify") are accepted. When disabled, keys without any usage designation are also accepted; keys explicitly restricted to encryption are still rejected. // Default: true
 *                     },
 *                     claim?: scalar|Param|null, // Claim which contains the user identifier (e.g.: sub, email..). // Default: "sub"
 *                     audience?: Param|string|list<scalar|Param|null>,
 *                     issuers?: list<scalar|Param|null>,
 *                     algorithms?: list<scalar|Param|null>,
 *                     keyset?: scalar|Param|null, // JSON-encoded JWKSet used to sign the token (must contain a list of valid public keys).
 *                     encryption?: bool|array{
 *                         enabled?: bool|Param, // Default: false
 *                         enforce?: bool|Param, // When enabled, the token shall be encrypted. // Default: false
 *                         algorithms?: list<scalar|Param|null>,
 *                         keyset?: scalar|Param|null, // JSON-encoded JWKSet used to decrypt the token (must contain a list of valid private keys).
 *                     },
 *                     allowed_time_drift?: int|Param, // Allowed time drift in seconds for token validation (iat, nbf, exp claims). // Default: 0
 *                     enforce_at_jwt_type?: bool|Param|null, // When enabled, the "typ" header of the token must be "at+jwt" or "application/at+jwt", as RFC 9068 requires from a JWT access token. This rejects the ID tokens issued for the same audience. Disable it only for providers that do not follow the profile. Defaults to false in 8.2 and to true as of 9.0. // Default: null
 *                 },
 *                 cas?: array{
 *                     validation_url?: scalar|Param|null, // CAS server validation URL
 *                     prefix?: scalar|Param|null, // CAS prefix // Default: "cas"
 *                     http_client?: scalar|Param|null, // HTTP Client service // Default: null
 *                 },
 *                 oauth2?: Param|string|array{
 *                     http_client?: scalar|Param|null, // HttpClient service id the introspection endpoint is called with. Declare it as a scoped client whose "base_uri" is the introspection endpoint of your authorization server and whose "auth_basic" holds the credentials it authenticates with. Those are sent as given, where the "client_secret_basic" of RFC 6749 §2.3.1 form-urlencodes both halves, so encode a client id or a secret holding a colon, a plus or a space yourself.
 *                     audience?: Param|string|list<scalar|Param|null>,
 *                     issuer?: scalar|Param|null, // Identifier of the authorization server, checked against the "iss" of the introspection response. // Default: null
 *                     claim?: scalar|Param|null, // Claim which contains the user identifier (e.g.: sub, username, email...). Defaults to "sub", falling back to "username". // Default: null
 *                     allowed_time_drift?: int|Param, // Allowed time drift in seconds when validating the "iat", "nbf" and "exp" of the introspection response. // Default: 0
 *                     cache?: array{ // Cache the introspection responses of active tokens, never beyond their "exp".
 *                         id?: scalar|Param|null, // Cache service id to use to cache the introspection responses.
 *                         ttl?: int|Param, // Maximum lifetime in seconds of a cached introspection response. The shorter it is, the sooner a revoked token stops being accepted. // Default: 60
 *                     },
 *                     response_signature?: bool|array{ // Ask the authorization server for a signed introspection response (RFC 9701) and verify it.
 *                         enabled?: bool|Param, // Default: false
 *                         enforce?: bool|Param, // When enabled (default), a plain JSON introspection response is refused. // Default: true
 *                         algorithms?: list<scalar|Param|null>,
 *                         discovery?: bool|array{ // Read the keys the introspection response is verified against from the RFC 8414 metadata of the authorization server, whose URL is derived from the "issuer" this handler already declares. Only the "jwks_uri" is read from it: which algorithms are accepted stays declared here, so that an authorization server cannot widen it by announcing more.
 *                             enabled?: bool|Param, // Default: false
 *                             cache?: array{
 *                                 id?: scalar|Param|null, // Cache service id the metadata document and the keys it points at are stored in. // Default: "cache.app"
 *                             },
 *                         },
 *                         keyset?: scalar|Param|null, // JSON-encoded JWKSet holding the public keys of your authorization server, the ones it announces at its "jwks_uri", which the introspection response is verified against. // Default: null
 *                     },
 *                 },
 *             },
 *             resource_metadata?: array{ // Declaring this node serves the RFC 9728 protected resource metadata document of the firewall at "/.well-known/oauth-protected-resource" and advertises its URL in the "resource_metadata" parameter of the "WWW-Authenticate" header, which is how a client discovers where to get a token this firewall accepts. The route is declared by the "security.authenticator.access_token.route_loader" service, which the application must import as it does for the logout routes; make sure it is reachable without a token.
 *                 resource?: scalar|Param|null, // The resource identifier of this firewall: an HTTPS URL, without a fragment (e.g. "https://api.example.com" or "https://example.com/api"). Its path component is inserted after the well-known path, as RFC 9728, Section 3.1 prescribes, so that one host can serve the metadata of several protected resources. Defaults to the origin the document is served from, which is what a firewall covering a whole application wants. // Default: null
 *                 authorization_servers?: Param|string|list<scalar|Param|null>,
 *                 jwks_uri?: scalar|Param|null, // URL of the JWK Set holding the keys this resource signs its own responses with. Unrelated to the keys the access tokens are verified against, which belong to the authorization server. // Default: null
 *                 scopes_supported?: Param|string|list<scalar|Param|null>,
 *                 bearer_methods_supported?: Param|string|list<"header"|"body"|"query"|Param>,
 *                 resource_name?: scalar|Param|null, // Human-readable name of this resource, meant to be displayed to the end user. // Default: null
 *                 resource_documentation?: scalar|Param|null, // URL of the developer documentation of this resource. // Default: null
 *                 resource_policy_uri?: scalar|Param|null, // URL of the policy telling how the client may use the data this resource exposes. // Default: null
 *                 resource_tos_uri?: scalar|Param|null, // URL of the terms of service of this resource. // Default: null
 *             },
 *         },
 *         http_basic?: array{
 *             provider?: scalar|Param|null,
 *             realm?: scalar|Param|null, // Default: "Secured Area"
 *         },
 *         http_basic_ldap?: array{
 *             provider?: scalar|Param|null,
 *             realm?: scalar|Param|null, // Default: "Secured Area"
 *             service?: scalar|Param|null, // Default: "ldap"
 *             dn_string?: scalar|Param|null, // Default: "{user_identifier}"
 *             query_string?: scalar|Param|null,
 *             search_dn?: scalar|Param|null, // Default: ""
 *             search_password?: scalar|Param|null, // Default: ""
 *             ldap_users_only?: bool|Param, // Only bind users of class "Symfony\Component\Ldap\Security\LdapUser" against the LDAP server, and leave any other user to the regular password checker. // Default: false
 *         },
 *         remember_me?: array{
 *             secret?: scalar|Param|null, // Default: "%kernel.secret%"
 *             service?: scalar|Param|null,
 *             user_providers?: Param|string|list<scalar|Param|null>,
 *             catch_exceptions?: bool|Param, // Default: true
 *             signature_properties?: list<scalar|Param|null>,
 *             token_provider?: Param|string|array{
 *                 service?: scalar|Param|null, // The service ID of a custom remember-me token provider.
 *                 doctrine?: bool|array{
 *                     enabled?: bool|Param, // Default: false
 *                     connection?: scalar|Param|null, // Default: null
 *                 },
 *             },
 *             token_verifier?: scalar|Param|null, // The service ID of a custom rememberme token verifier.
 *             name?: scalar|Param|null, // Default: "REMEMBERME"
 *             lifetime?: int|Param, // Default: 31536000
 *             path?: scalar|Param|null, // Default: "/"
 *             domain?: scalar|Param|null, // Default: null
 *             secure?: true|false|"auto"|Param, // Defaults to the value of "framework.session.cookie_secure", or to "auto".
 *             httponly?: bool|Param, // Default: true
 *             samesite?: null|"lax"|"strict"|"none"|Param, // Defaults to the value of "framework.session.cookie_samesite", or to "lax".
 *             always_remember_me?: bool|Param, // Default: false
 *             remember_me_parameter?: scalar|Param|null, // Default: "_remember_me"
 *         },
 *     }>,
 *     access_control?: list<array{ // Default: []
 *         request_matcher?: scalar|Param|null, // Default: null
 *         requires_channel?: scalar|Param|null, // Default: null
 *         path?: scalar|Param|null, // Use the urldecoded format. // Default: null
 *         host?: scalar|Param|null, // Default: null
 *         port?: int|Param, // Default: null
 *         ips?: Param|string|list<scalar|Param|null>,
 *         attributes?: array<string, scalar|Param|null>,
 *         route?: scalar|Param|null, // Default: null
 *         methods?: Param|string|list<scalar|Param|null>,
 *         allow_if?: scalar|Param|null, // Default: null
 *         roles?: Param|string|list<scalar|Param|null>,
 *     }>,
 *     role_hierarchy?: array<string, Param|string|list<scalar|Param|null>>,
 * }
 * @psalm-type WebProfilerConfig = array{
 *     toolbar?: bool|array{ // Profiler toolbar configuration
 *         enabled?: bool|Param, // Default: false
 *         ajax_replace?: bool|Param, // Replace toolbar on AJAX requests // Default: false
 *     },
 *     intercept_redirects?: bool|Param, // Default: false
 *     excluded_ajax_paths?: scalar|Param|null, // Default: "^/((index|app(_[\\w]+)?)\\.php/)?_wdt"
 * }
 * @psalm-type ConfigType = array{
 *     imports?: ImportsConfig,
 *     parameters?: ParametersConfig,
 *     services?: ServicesConfig,
 *     router?: RouterConfig,
 *     cache?: CacheConfig,
 *     asset?: AssetConfig,
 *     serializer?: SerializerConfig,
 *     validation?: ValidationConfig,
 *     type_info?: TypeInfoConfig,
 *     property_access?: PropertyAccessConfig,
 *     property_info?: PropertyInfoConfig,
 *     framework?: FrameworkConfig,
 *     twig?: TwigConfig,
 *     twig_extra?: TwigExtraConfig,
 *     security?: SecurityConfig,
 *     "when@dev"?: array{
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         router?: RouterConfig,
 *         cache?: CacheConfig,
 *         asset?: AssetConfig,
 *         serializer?: SerializerConfig,
 *         validation?: ValidationConfig,
 *         type_info?: TypeInfoConfig,
 *         property_access?: PropertyAccessConfig,
 *         property_info?: PropertyInfoConfig,
 *         framework?: FrameworkConfig,
 *         twig?: TwigConfig,
 *         twig_extra?: TwigExtraConfig,
 *         security?: SecurityConfig,
 *         web_profiler?: WebProfilerConfig,
 *     },
 *     "when@prod"?: array{
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         router?: RouterConfig,
 *         cache?: CacheConfig,
 *         asset?: AssetConfig,
 *         serializer?: SerializerConfig,
 *         validation?: ValidationConfig,
 *         type_info?: TypeInfoConfig,
 *         property_access?: PropertyAccessConfig,
 *         property_info?: PropertyInfoConfig,
 *         framework?: FrameworkConfig,
 *         twig?: TwigConfig,
 *         twig_extra?: TwigExtraConfig,
 *         security?: SecurityConfig,
 *     },
 *     "when@test"?: array{
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         router?: RouterConfig,
 *         cache?: CacheConfig,
 *         asset?: AssetConfig,
 *         serializer?: SerializerConfig,
 *         validation?: ValidationConfig,
 *         type_info?: TypeInfoConfig,
 *         property_access?: PropertyAccessConfig,
 *         property_info?: PropertyInfoConfig,
 *         framework?: FrameworkConfig,
 *         twig?: TwigConfig,
 *         twig_extra?: TwigExtraConfig,
 *         security?: SecurityConfig,
 *         web_profiler?: WebProfilerConfig,
 *     },
 *     ...<string, ExtensionType|array{ // extra keys must follow the when@%env% pattern or match an extension alias
 *         imports?: ImportsConfig,
 *         parameters?: ParametersConfig,
 *         services?: ServicesConfig,
 *         ...<string, ExtensionType>,
 *     }>
 * }
 */
final class App
{
    /**
     * @param ConfigType $config
     *
     * @psalm-return ConfigType
     */
    public static function config(array $config): array
    {
        /** @var ConfigType $config */
        $config = AppReference::config($config);

        return $config;
    }
}

namespace Symfony\Component\Routing\Loader\Configurator;

/**
 * This class provides array-shapes for configuring the routes of an application.
 *
 * Example:
 *
 *     ```php
 *     // config/routes.php
 *     namespace Symfony\Component\Routing\Loader\Configurator;
 *
 *     return Routes::config([
 *         'controllers' => [
 *             'resource' => 'routing.controllers',
 *         ],
 *     ]);
 *     ```
 *
 * @psalm-type RouteConfig = array{
 *     path: string|array<string,string>,
 *     controller?: string,
 *     methods?: string|list<string>,
 *     requirements?: array<string,string>,
 *     defaults?: array<string,mixed>,
 *     options?: array<string,mixed>,
 *     host?: string|array<string,string>,
 *     schemes?: string|list<string>,
 *     condition?: string,
 *     add_condition?: string,
 *     locale?: string,
 *     format?: string,
 *     utf8?: bool,
 *     stateless?: bool,
 *     firewall?: string,
 * }
 * @psalm-type ImportConfig = array{
 *     resource: string,
 *     type?: string,
 *     exclude?: string|list<string>,
 *     prefix?: string|array<string,string>,
 *     name_prefix?: string,
 *     trailing_slash_on_root?: bool,
 *     controller?: string,
 *     methods?: string|list<string>,
 *     requirements?: array<string,string>,
 *     defaults?: array<string,mixed>,
 *     options?: array<string,mixed>,
 *     host?: string|array<string,string>,
 *     schemes?: string|list<string>,
 *     condition?: string,
 *     add_condition?: string,
 *     locale?: string,
 *     format?: string,
 *     utf8?: bool,
 *     stateless?: bool,
 *     firewall?: string,
 * }
 * @psalm-type AliasConfig = array{
 *     alias: string,
 *     deprecated?: array{package:string, version:string, message?:string},
 * }
 * @psalm-type RoutesConfig = array{
 *     "when@dev"?: array<string, RouteConfig|ImportConfig|AliasConfig>,
 *     "when@prod"?: array<string, RouteConfig|ImportConfig|AliasConfig>,
 *     "when@test"?: array<string, RouteConfig|ImportConfig|AliasConfig>,
 *     ...<string, RouteConfig|ImportConfig|AliasConfig>
 * }
 */
final class Routes
{
    /**
     * @param RoutesConfig $config
     *
     * @psalm-return RoutesConfig
     */
    public static function config(array $config): array
    {
        return $config;
    }
}
