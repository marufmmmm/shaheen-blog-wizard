<?php
/**
 * AI models & providers: Claude, Kimi (Moonshot), DeepSeek, Gemini, OpenRouter.
 * Each task (chat, blog, SEO, bulk, images) can use a different model.
 * Anthropic, Kimi and DeepSeek use the Anthropic Messages format; Gemini and OpenRouter
 * use the OpenAI format and are translated both ways, so the rest of the plugin is unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function csa_providers() {
	return array(
		'anthropic'  => array( 'label' => 'Anthropic (Claude)', 'protocol' => 'anthropic', 'url' => 'https://api.anthropic.com/v1/messages', 'opt' => 'api_key', 'signup' => 'https://console.anthropic.com/settings/keys' ),
		'moonshot'   => array( 'label' => 'Moonshot (Kimi)', 'protocol' => 'anthropic', 'url' => 'https://api.moonshot.ai/anthropic/v1/messages', 'opt' => 'key_moonshot', 'signup' => 'https://platform.moonshot.ai/console/api-keys' ),
		'openai'     => array( 'label' => 'OpenAI (ChatGPT)', 'protocol' => 'openai', 'url' => 'https://api.openai.com/v1/chat/completions', 'opt' => 'key_openai', 'signup' => 'https://platform.openai.com/api-keys' ),
	);
}

/**
 * Built-in models. Prices: USD per 1M tokens (input, output, cached input), checked Sep 2026.
 * code/bn = ratings 1-5 for page design & code, and for Bangla writing (our assessment; test on your site).
 * tier: 'save' = cheap everyday choice, 'good' = balanced, 'premium' = expensive, only for special cases.
 */
function csa_builtin_models() {
	return array(
		// Anthropic (Claude)
		'haiku55'        => array( 'provider' => 'anthropic', 'id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'in' => 0.1, 'out' => 0.5, 'cache' => 0.01, 'vision' => true, 'code' => 4, 'bn' => 4, 'tier' => 'save', 'best' => 'New (Oct 2026), very cheap Claude: SEO audits, titles and descriptions, bulk work. Prices rise to $0.50/$2.50 only when one request is over 100,000 tokens.' ),
		'sonnet55'       => array( 'provider' => 'anthropic', 'id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'in' => 2, 'out' => 10, 'cache' => 0.2, 'vision' => true, 'code' => 5, 'bn' => 5, 'tier' => 'good', 'best' => 'Newest Sonnet: hard page design, Elementor fixes and your best Bangla writing.' ),
		'opus'           => array( 'provider' => 'anthropic', 'id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'in' => 4, 'out' => 20, 'cache' => 0.2, 'vision' => true, 'code' => 5, 'bn' => 5, 'tier' => 'premium', 'best' => 'Very hard redesigns only; twice the Sonnet price, rarely needed here' ),
		// Moonshot (Kimi)
		'kimi-k3'        => array( 'provider' => 'moonshot', 'id' => 'kimi-k3', 'label' => 'Kimi K3', 'in' => 3, 'out' => 15, 'cache' => 0.3, 'vision' => true, 'code' => 5, 'bn' => 3, 'tier' => 'premium', 'best' => 'Kimi\'s strongest model; priced above Sonnet, so rarely worth it here' ),
		'kimi-k31'       => array( 'provider' => 'moonshot', 'id' => 'kimi-k3.1-pro', 'label' => 'Kimi K3.1 Pro', 'in' => 2, 'out' => 10, 'cache' => 0.2, 'vision' => true, 'code' => 5, 'bn' => 3, 'tier' => 'good', 'best' => 'Newer, cheaper than K3 (1M context)' ),
		'kimi-k31-fast'  => array( 'provider' => 'moonshot', 'id' => 'kimi-k3.1-pro-highspeed', 'label' => 'Kimi K3.1 Pro Highspeed', 'in' => 4, 'out' => 20, 'cache' => 0.2, 'vision' => true, 'code' => 5, 'bn' => 3, 'tier' => 'premium', 'best' => 'Same as K3.1 Pro but faster; double the price' ),
		// DeepSeek
		// OpenAI (ChatGPT)
		'gpt-terra'      => array( 'provider' => 'openai', 'id' => 'gpt-6.1-terra', 'label' => 'GPT-6.1 Terra', 'in' => 1, 'out' => 5, 'cache' => 0.05, 'vision' => true, 'code' => 5, 'bn' => 5, 'tier' => 'good', 'best' => 'ChatGPT Terra: fast, cheap, strong Bangla; good default for writing, SEO text and edits' ),
		'gpt-astra'      => array( 'provider' => 'openai', 'id' => 'gpt-6-astra', 'label' => 'GPT-6 Astra', 'in' => 10, 'out' => 50, 'cache' => 1, 'vision' => true, 'code' => 5, 'bn' => 5, 'tier' => 'premium', 'best' => 'OpenAI\'s strongest and most expensive model; not needed for website work' ),
		// Google (Gemini)
	);
}

/** Cheapest sensible order to try for each task, used when the chosen model has no API key. */
function csa_task_priority() {
	return array(
		'chat'   => array( 'gpt-terra', 'kimi-k31', 'sonnet55', 'haiku55' ),
		'blog'   => array( 'gpt-terra', 'sonnet55', 'kimi-k31', 'haiku55' ),
		'seo'    => array( 'gpt-terra', 'kimi-k31', 'haiku55', 'sonnet55' ),
		'bulk'   => array( 'gpt-terra', 'haiku55', 'kimi-k31' ),
		'vision' => array( 'gpt-terra', 'haiku55', 'kimi-k31', 'sonnet55' ),
	);
}

/** Custom models from Settings: "key | provider | model-id | input | output | vision(yes/no) | label". */
function csa_parse_custom_models( $text ) {
	$out = array();
	foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
		$p = array_map( 'trim', explode( '|', $line ) );
		if ( count( $p ) < 5 || '' === $p[0] || ! isset( csa_providers()[ $p[1] ] ) ) {
			continue;
		}
		$out[ sanitize_key( $p[0] ) ] = array(
			'provider' => $p[1],
			'id'       => $p[2],
			'label'    => ! empty( $p[6] ) ? $p[6] : $p[2],
			'in'       => (float) $p[3],
			'out'      => (float) $p[4],
			'cache'    => (float) $p[3],
			'vision'   => in_array( strtolower( $p[5] ?? '' ), array( 'yes', 'y', '1', 'true' ), true ),
			'code'     => 0,
			'bn'       => 0,
			'best'     => 'Custom model',
			'custom'   => true,
		);
	}
	return $out;
}

function csa_models() {
	static $all = null;
	if ( null === $all ) {
		$all = array_merge( csa_builtin_models(), csa_parse_custom_models( csa_opt( 'custom_models' ) ) );
	}
	return $all;
}

function csa_model( $key ) {
	$m = csa_models();
	return isset( $m[ $key ] ) ? array_merge( $m[ $key ], array( 'key' => $key ) ) : null;
}

function csa_tasks() {
	return array(
		'chat'   => array( 'label' => csa_t( 'Chat: page design & editing' ), 'default' => 'gpt-terra' ),
		'blog'   => array( 'label' => csa_t( 'Blog Studio: plans & writing' ), 'default' => 'gpt-terra' ),
		'seo'    => array( 'label' => csa_t( 'Single SEO audit' ), 'default' => 'gpt-terra' ),
		'bulk'   => array( 'label' => csa_t( 'Bulk work: bulk audits, keywords, ideas, weekly audit' ), 'default' => 'gpt-terra' ),
		'vision' => array( 'label' => csa_t( 'Images: alt text, screenshots' ), 'default' => 'gpt-terra' ),
	);
}

function csa_presets() {
	return array(
		'terra-astra' => array( 'label' => '⭐ GPT Terra + Astra', 'chat' => 'gpt-terra', 'blog' => 'gpt-terra', 'seo' => 'gpt-terra', 'bulk' => 'gpt-terra', 'vision' => 'gpt-terra' ),
		'quality'     => array( 'label' => csa_t( 'Best quality' ), 'chat' => 'sonnet55', 'blog' => 'sonnet55', 'seo' => 'gpt-terra', 'bulk' => 'haiku55', 'vision' => 'haiku55' ),
		'kimi'        => array( 'label' => 'Kimi K3.1 Pro', 'chat' => 'kimi-k31', 'blog' => 'kimi-k31', 'seo' => 'kimi-k31', 'bulk' => 'kimi-k31', 'vision' => 'kimi-k31' ),
		'cheapest'    => array( 'label' => csa_t( 'Lowest cost' ), 'chat' => 'haiku55', 'blog' => 'haiku55', 'seo' => 'haiku55', 'bulk' => 'haiku55', 'vision' => 'haiku55' ),
	);
}

function csa_task_model_key( $task ) {
	$t   = csa_tasks();
	$key = csa_opt( 'm_' . $task );
	$key = ( $key && csa_model( $key ) ) ? $key : ( $t[ $task ]['default'] ?? 'gpt-terra' );
	// "Just add a key": if the chosen model's provider has no key, use the cheapest sensible model that has one.
	if ( '' === csa_provider_key( csa_model( $key )['provider'] ) ) {
		foreach ( csa_task_priority()[ $task ] ?? array() as $k ) {
			if ( csa_model( $k ) && '' !== csa_provider_key( csa_model( $k )['provider'] ) ) {
				return $k;
			}
		}
	}
	return $key;
}

function csa_provider_key( $provider ) {
	$p = csa_providers()[ $provider ] ?? null;
	return $p ? (string) csa_opt( $p['opt'] ) : '';
}

function csa_has_any_key() {
	foreach ( csa_providers() as $k => $p ) {
		if ( csa_provider_key( $k ) ) {
			return true;
		}
	}
	return false;
}

/** How many times cheaper than Sonnet for a typical request (3 parts reading : 1 part writing). */
function csa_model_ratio( $m ) {
	$s = csa_builtin_models()['sonnet55'];
	$a = 3 * $s['in'] + $s['out'];
	$b = 3 * $m['in'] + $m['out'];
	return $b > 0 ? $a / $b : 1;
}

/* ================================================================== */
/* The single entry point for every AI call                            */
/* ================================================================== */

/**
 * $args: task (chat|blog|seo|bulk|vision), model_key (override), tools, max_tokens,
 *        cache_messages, feature, needs_vision.
 * Always returns an Anthropic-shaped response array, or WP_Error.
 */
function csa_claude( $messages, $system, $args = array() ) {
	$task = $args['task'] ?? 'chat';
	$key  = ! empty( $args['model_key'] ) && csa_model( $args['model_key'] ) ? $args['model_key'] : csa_task_model_key( $task );
	// A model that just timed out is skipped for a while: use a faster one that has a key.
	if ( get_transient( 'csa_slow_' . $key ) ) {
		$fb = csa_fallback_key( $key );
		if ( $fb ) {
			$key                     = $fb;
			$GLOBALS['csa_alerts'][] = sprintf( csa_t( 'The chosen model was too slow, so %s is used for now.' ), csa_model( $fb )['label'] );
		}
	}
	$m    = csa_model( $key );
	if ( ! empty( $args['needs_vision'] ) && ! $m['vision'] ) {
		$m = csa_model( csa_task_model_key( 'vision' ) ); // Images need a model that can see them.
	}
	$prov = csa_providers()[ $m['provider'] ];
	$api  = csa_provider_key( $m['provider'] );
	if ( '' === $api ) {
		return new WP_Error( 'key', sprintf( csa_t( 'Add the %1$s API key in Settings to use %2$s.' ), $prov['label'], $m['label'] ) );
	}
	if ( csa_usage_percent() >= 100 ) {
		return new WP_Error( 'budget', csa_alert_text( 100 ) );
	}
	$day_cap = csa_budget_base() * (int) csa_opt( 'day_cap_pct' ) / 100;
	if ( csa_eco() && $day_cap > 0 && csa_day_cost() >= $day_cap ) {
		return new WP_Error( 'day', sprintf( csa_t( "Today's AI limit is reached (%d%% of the monthly budget per day). It resets tomorrow; an admin can change it in Settings > Economy mode." ), (int) csa_opt( 'day_cap_pct' ) ) );
	}
	$messages = csa_messages_for_model( $messages, $m );
	$max      = (int) ( $args['max_tokens'] ?? 8000 );
	$tools    = ! empty( $args['tools'] ) ? array_values( $args['tools'] ) : array();

	if ( 'openai' === $prov['protocol'] ) {
		$body    = csa_to_openai( $messages, $system, $tools, $max, $m['id'] );
		$headers = array( 'Authorization' => 'Bearer ' . $api, 'content-type' => 'application/json' );
		if ( 'openrouter' === $m['provider'] ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = csa_brand();
		}
	} else {
		$is_claude = 'anthropic' === $m['provider'];
		$body      = array(
			'model'      => $m['id'],
			'max_tokens' => $max,
			'system'     => $is_claude ? array( array( 'type' => 'text', 'text' => $system, 'cache_control' => array( 'type' => 'ephemeral' ) ) ) : $system,
			'messages'   => $messages,
		);
		if ( $tools ) {
			if ( $is_claude ) {
				$tools[ count( $tools ) - 1 ]['cache_control'] = array( 'type' => 'ephemeral' );
			}
			$body['tools'] = $tools;
		}
		if ( $is_claude && ! empty( $args['cache_messages'] ) && $messages ) {
			$li = count( $body['messages'] ) - 1;
			$c  = $body['messages'][ $li ]['content'];
			if ( is_string( $c ) ) {
				$c = array( array( 'type' => 'text', 'text' => $c ) );
			}
			if ( $c ) {
				// The chat-memory block changes every turn: cache up to the block before it.
				$ci = count( $c ) - 1;
				if ( $ci > 0 && ! empty( $c[ $ci ]['_csa_mem'] ) ) {
					$ci--;
				}
				$c[ $ci ]['cache_control'] = array( 'type' => 'ephemeral' );
				$body['messages'][ $li ]['content']    = $c;
			}
		}
		$li = count( $body['messages'] ) - 1;
		if ( $li >= 0 && is_array( $body['messages'][ $li ]['content'] ) ) {
			foreach ( $body['messages'][ $li ]['content'] as &$mb ) {
				unset( $mb['_csa_mem'] ); // internal marker, never sent
			}
			unset( $mb );
		}
		$headers = array( 'x-api-key' => $api, 'Authorization' => 'Bearer ' . $api, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' );
		if ( 'moonshot' === $m['provider'] && ! get_transient( 'csa_kimi_nothink_bad' ) ) {
			// Kimi "thinking" can take minutes before the first byte; page edits and SEO do not need it.
			$body['thinking'] = array( 'type' => 'disabled' );
		}
	}

	csa_keepalive_start();
	$resp = csa_http( 'POST', $prov['url'], array( 'timeout' => 110, 'headers' => $headers, 'body' => wp_json_encode( $body, CSA_JSON ) ) );
	if ( is_wp_error( $resp ) ) {
		$why = $resp->get_error_message();
		if ( preg_match( '/timed out|timeout|cURL error 28/i', $why ) ) {
			$fb = csa_fallback_key( $m['key'] );
			if ( $fb ) {
				set_transient( 'csa_slow_' . $m['key'], 1, 30 * MINUTE_IN_SECONDS );
				$why .= ' — ' . sprintf( csa_t( '%1$s did not answer in time. The next try uses %2$s.' ), $m['label'], csa_model( $fb )['label'] );
			} else {
				$why .= ' — ' . sprintf( csa_t( '%s did not answer in time. Try again, or choose a faster model in the chat model list.' ), $m['label'] );
			}
		}
		return new WP_Error( 'net', csa_t( 'Could not reach the AI service:' ) . ' ' . $why );
	}
	$code = (int) $resp['code'];
	$raw  = json_decode( $resp['body'], true );
	if ( 400 === $code && isset( $body['thinking'] ) && 'moonshot' === $m['provider'] && false !== stripos( (string) $resp['body'], 'thinking' ) ) {
		// This Kimi model does not accept the switch: remember that and ask again without it.
		set_transient( 'csa_kimi_nothink_bad', 1, DAY_IN_SECONDS );
		unset( $body['thinking'] );
		$resp = csa_http( 'POST', $prov['url'], array( 'timeout' => 110, 'headers' => $headers, 'body' => wp_json_encode( $body, CSA_JSON ) ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'net', csa_t( 'Could not reach the AI service:' ) . ' ' . $resp->get_error_message() );
		}
		$code = (int) $resp['code'];
		$raw  = json_decode( $resp['body'], true );
	}
	if ( 200 !== $code || ! is_array( $raw ) || ! empty( $raw['error'] ) ) {
		$msg = is_array( $raw['error'] ?? null ) ? ( $raw['error']['message'] ?? wp_json_encode( $raw['error'] ) ) : ( $raw['error'] ?? ( 'HTTP ' . $code ) );
		if ( in_array( $code, array( 429, 503, 529 ), true ) ) {
			$msg = csa_t( 'The AI service is busy right now. Please retry in a minute.' ) . ' (' . $code . ')';
		} elseif ( in_array( $code, array( 401, 403 ), true ) ) {
			$msg = sprintf( csa_t( 'The %s API key was rejected. Check it in Settings.' ), $prov['label'] ) . ' (' . $code . ')';
		}
		return new WP_Error( 'api', csa_t( 'AI error:' ) . ' ' . $m['label'] . ': ' . $msg );
	}
	$data = 'openai' === $prov['protocol'] ? csa_from_openai( $raw ) : $raw;
	if ( empty( $data['content'] ) ) {
		$data['content'] = array( array( 'type' => 'text', 'text' => '(empty reply)' ) );
	}
	csa_record_usage( $m, (array) ( $data['usage'] ?? array() ), $args['feature'] ?? '' );
	$data['_model'] = $m['key'];
	return $data;
}

/** A faster model from another provider (or a cheaper Claude) that has an API key; '' if none. */
function csa_fallback_key( $not ) {
	$cur = csa_model( $not );
	foreach ( array( 'gpt-terra', 'haiku55', 'sonnet55' ) as $k ) {
		$m = csa_model( $k );
		if ( $m && $k !== $not && '' !== csa_provider_key( $m['provider'] ) && ( ! $cur || $m['provider'] !== $cur['provider'] || $m['tier'] === 'save' ) ) {
			return $k;
		}
	}
	return '';
}

/** Adapt stored history to the chosen model: images, thinking blocks, tool ids. */
function csa_messages_for_model( $messages, $m ) {
	foreach ( $messages as &$msg ) {
		if ( ! is_array( $msg['content'] ) ) {
			continue;
		}
		$new = array();
		foreach ( $msg['content'] as $b ) {
			$type = $b['type'] ?? '';
			if ( 'image' === $type && ! $m['vision'] ) {
				$b = array( 'type' => 'text', 'text' => '[image not shown: ' . $m['label'] . ' cannot see images]' );
			}
			if ( in_array( $type, array( 'thinking', 'redacted_thinking' ), true ) && ( 'anthropic' === $m['provider'] || 'openai' === csa_providers()[ $m['provider'] ]['protocol'] ) ) {
				continue; // Reasoning notes from other models are not valid input here.
			}
			$new[] = $b;
		}
		if ( ! $new ) {
			$new = array( array( 'type' => 'text', 'text' => '…' ) );
		}
		$msg['content'] = $new;
	}
	unset( $msg );
	return $messages;
}

/* ================================================================== */
/* OpenAI format translation (Gemini, OpenRouter)                      */
/* ================================================================== */

function csa_to_openai( $messages, $system, $tools, $max, $model_id ) {
	$out = array( array( 'role' => 'system', 'content' => $system ) );
	foreach ( $messages as $msg ) {
		if ( is_string( $msg['content'] ) ) {
			$out[] = array( 'role' => $msg['role'], 'content' => $msg['content'] );
			continue;
		}
		if ( 'assistant' === $msg['role'] ) {
			$text  = '';
			$calls = array();
			foreach ( $msg['content'] as $b ) {
				if ( 'text' === ( $b['type'] ?? '' ) ) {
					$text .= $b['text'];
				} elseif ( 'tool_use' === ( $b['type'] ?? '' ) ) {
					$calls[] = array( 'id' => $b['id'], 'type' => 'function', 'function' => array( 'name' => $b['name'], 'arguments' => wp_json_encode( empty( $b['input'] ) ? new stdClass() : $b['input'], CSA_JSON ) ) );
				}
			}
			$row = array( 'role' => 'assistant', 'content' => '' !== $text ? $text : null );
			if ( $calls ) {
				$row['tool_calls'] = $calls;
			}
			$out[] = $row;
			continue;
		}
		$parts = array();
		foreach ( $msg['content'] as $b ) {
			$type = $b['type'] ?? '';
			if ( 'tool_result' === $type ) {
				$out[] = array( 'role' => 'tool', 'tool_call_id' => $b['tool_use_id'], 'content' => is_string( $b['content'] ) ? $b['content'] : wp_json_encode( $b['content'], CSA_JSON ) );
			} elseif ( 'text' === $type ) {
				$parts[] = array( 'type' => 'text', 'text' => $b['text'] );
			} elseif ( 'image' === $type && 'base64' === ( $b['source']['type'] ?? '' ) ) {
				$parts[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:' . $b['source']['media_type'] . ';base64,' . $b['source']['data'] ) );
			}
		}
		if ( $parts ) {
			$out[] = array( 'role' => 'user', 'content' => ( 1 === count( $parts ) && 'text' === $parts[0]['type'] ) ? $parts[0]['text'] : $parts );
		}
	}
	$body = array( 'model' => $model_id, 'messages' => $out, 'max_tokens' => $max );
	if ( 0 === strpos( $model_id, 'gpt-' ) || 0 === strpos( $model_id, 'o' ) && preg_match( '/^o\d/', $model_id ) ) {
		// OpenAI's newer models take max_completion_tokens instead of max_tokens.
		unset( $body['max_tokens'] );
		$body['max_completion_tokens'] = $max;
	}
	if ( $tools ) {
		$body['tools'] = array_map(
			function ( $t ) {
				return array( 'type' => 'function', 'function' => array( 'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema'] ) );
			},
			$tools
		);
		$body['tool_choice'] = 'auto';
	}
	return $body;
}

function csa_from_openai( $raw ) {
	$choice  = $raw['choices'][0] ?? array();
	$msg     = $choice['message'] ?? array();
	$content = array();
	$text    = $msg['content'] ?? '';
	if ( is_array( $text ) ) {
		$text = implode( '', wp_list_pluck( array_filter( $text, function ( $p ) { return isset( $p['text'] ); } ), 'text' ) );
	}
	if ( '' !== trim( (string) $text ) ) {
		$content[] = array( 'type' => 'text', 'text' => (string) $text );
	}
	foreach ( (array) ( $msg['tool_calls'] ?? array() ) as $tc ) {
		$args = json_decode( $tc['function']['arguments'] ?? '{}', true );
		$id   = preg_replace( '/[^a-zA-Z0-9_-]/', '_', (string) ( $tc['id'] ?? '' ) );
		$content[] = array( 'type' => 'tool_use', 'id' => $id ? $id : 'call_' . substr( md5( uniqid( '', true ) ), 0, 12 ), 'name' => $tc['function']['name'] ?? '', 'input' => is_array( $args ) ? $args : array() );
	}
	$has_tools = (bool) array_filter( $content, function ( $b ) { return 'tool_use' === $b['type']; } );
	$finish    = $choice['finish_reason'] ?? 'stop';
	$u         = (array) ( $raw['usage'] ?? array() );
	$cached    = (int) ( $u['prompt_tokens_details']['cached_tokens'] ?? 0 );
	return array(
		'content'     => $content,
		'stop_reason' => $has_tools ? 'tool_use' : ( 'length' === $finish ? 'max_tokens' : 'end_turn' ),
		'usage'       => array( 'input_tokens' => max( 0, (int) ( $u['prompt_tokens'] ?? 0 ) - $cached ), 'cache_read_input_tokens' => $cached, 'output_tokens' => (int) ( $u['completion_tokens'] ?? 0 ) ),
	);
}


/** One-time: the Kimi K2 models were removed; move saved choices to Kimi K3.1 Pro. */
add_action( 'init', function () {
	if ( '1' === get_option( 'csa_k2_gone' ) ) {
		return;
	}
	$o = get_option( CSA_OPT, array() );
	if ( is_array( $o ) ) {
		foreach ( $o as $k => $v ) {
			if ( 0 === strpos( (string) $k, 'm_' ) && in_array( $v, array( 'kimi', 'kimi-code' ), true ) ) {
				$o[ $k ] = 'kimi-k31';
			}
		}
		update_option( CSA_OPT, $o );
	}
	update_option( 'csa_k2_gone', '1', false );
}, 7 );


/** One-time: removed models/providers -> Terra; new defaults for brand, language, weekly audit, live pages, roles. */
add_action( 'init', function () {
	if ( '1' === get_option( 'csa_plan_v2' ) ) {
		return;
	}
	$o = get_option( CSA_OPT, array() );
	$o = is_array( $o ) ? $o : array();
	$ok = array_keys( csa_models() );
	foreach ( array( 'chat', 'blog', 'seo', 'bulk', 'vision' ) as $t ) {
		if ( empty( $o[ 'm_' . $t ] ) || ! in_array( $o[ 'm_' . $t ], $ok, true ) ) {
			$o[ 'm_' . $t ] = 'gpt-terra';
		}
	}
	$o['brand']        = 'Blog Write .ai';
	$o['ui_lang']      = 'bn';
	$o['seo_lang']     = 'Bangla';
	$o['weekly_audit'] = 0;
	$o['allow_live']   = 0;
	$o['roles_chat']   = array( 'administrator' );
	$o['roles_seo']    = array( 'administrator', 'editor' );
	$o['roles_blog']   = array( 'administrator', 'editor' );
	$o['roles_images'] = array( 'administrator', 'editor' );
	update_option( CSA_OPT, $o );
	update_option( 'csa_plan_v2', '1', false );
}, 7 );
