<?php
/**
 * Plugin Name: Russian Stem Search
 * Plugin URI:  https://github.com/jekfev/ru-stem-search
 * Description: Быстрый поиск WordPress по основам русских слов (стеммер Snowball) на полнотекстовом индексе MySQL. «Дорога», «дороги», «дорогу» дают один результат; заголовок весит больше текста.
 * Version:     1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.1
 * Author:      jekfev
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ru-stem-search
 */
defined('ABSPATH') || exit;

const SG_SEARCH_DB_VER = 1;
const SG_SEARCH_TYPES = ['post', 'page'];

/* ---------- Русский стеммер (алгоритм Snowball Russian) ---------- */

function sg_stem_ru($w) {
	static $cache = [];
	if (isset($cache[$w])) return $cache[$w];
	$orig = $w;
	$v = 'аеиоуыэюя';
	if (!preg_match("/^(.*?[$v])(.*)$/u", $w, $m)) return $cache[$orig] = $w;
	[$pre, $rv] = [$m[1], $m[2]];

	// R2 — по всему слову: R1 = после первой согласной, идущей за гласной; R2 = то же внутри R1
	$r2 = mb_strlen($w);
	if (preg_match("/^.*?[$v][^$v]/u", $w, $a)) {
		$r1 = mb_strlen($a[0]);
		if (preg_match("/^.*?[$v][^$v]/u", mb_substr($w, $r1), $b)) $r2 = $r1 + mb_strlen($b[0]);
	}

	// Шаг 1. Самое длинное окончание выбирает сам регэксп: левое совпадение с якорем $ = самое длинное.
	$gerund = '/(?:(?<=[ая])(?:в|вши|вшись)|ив|ивши|ившись|ыв|ывши|ывшись)$/u';
	if (preg_match($gerund, $rv)) {
		$rv = preg_replace($gerund, '', $rv);
	} else {
		$rv = preg_replace('/(?:ся|сь)$/u', '', $rv);
		$adj  = 'ее|ие|ые|ое|ими|ыми|ей|ий|ый|ой|ем|им|ым|ом|его|ого|ему|ому|их|ых|ую|юю|ая|яя|ою|ею';
		$adjectival = "/(?:(?:ивш|ывш|ующ)|(?<=[ая])(?:ем|нн|вш|ющ|щ))?(?:$adj)$/u";
		$verb = '/(?:(?<=[ая])(?:ла|на|ете|йте|ли|й|л|ем|н|ло|но|ет|ют|ны|ть|ешь|нно)|ила|ыла|ена|ейте|уйте|ите|или|ыли|ей|уй|ил|ыл|им|ым|ен|ило|ыло|ено|ят|ует|уют|ит|ыт|ены|ить|ыть|ишь|ую|ю)$/u';
		$noun = '/(?:а|ев|ов|ие|ье|е|иями|ями|ами|еи|ии|и|ией|ей|ой|ий|й|иям|ям|ием|ем|ам|ом|о|у|ах|иях|ях|ы|ь|ию|ью|ю|ия|ья|я)$/u';
		if (preg_match($adjectival, $rv))  $rv = preg_replace($adjectival, '', $rv);
		elseif (preg_match($verb, $rv))    $rv = preg_replace($verb, '', $rv);
		else                               $rv = preg_replace($noun, '', $rv);
	}
	// Шаг 2
	$rv = preg_replace('/и$/u', '', $rv);
	// Шаг 3: словообразовательное -ость в R2
	if (preg_match('/(ость|ост)$/u', $rv, $d) && mb_strlen($pre . $rv) - mb_strlen($d[1]) >= $r2) {
		$rv = mb_substr($rv, 0, mb_strlen($rv) - mb_strlen($d[1]));
	}
	// Шаг 4
	if (preg_match('/нн$/u', $rv))              $rv = mb_substr($rv, 0, -1);
	elseif (preg_match('/(ейше|ейш)$/u', $rv))  { $rv = preg_replace('/(ейше|ейш)$/u', '', $rv); if (preg_match('/нн$/u', $rv)) $rv = mb_substr($rv, 0, -1); }
	else                                        $rv = preg_replace('/ь$/u', '', $rv);

	if (count($cache) > 50000) $cache = [];
	return $cache[$orig] = $pre . $rv;
}

/* ---------- Текст → основы ---------- */

function sg_search_stopwords() {
	static $s = null;
	if ($s !== null) return $s;
	return $s = array_flip(explode(' ', 'и в во не что он на я с со как а то все она так его но да ты к у же вы за бы по только ее мне было вот от меня еще нет о из ему теперь когда даже ну вдруг ли если уже или ни быть был него до вас нибудь опять уж вам ведь там потом себя ничего ей может они тут где есть надо ней для мы тебя их чем была сам чтоб без будто чего раз тоже себе под будет ж тогда кто этот того потому этого какой совсем ним здесь этом один почти мой тем чтобы нее сейчас были куда зачем всех никогда можно при наконец два об другой хоть после над больше тот через эти нас про всего них какая много разве три эту моя впрочем хорошо свою этой перед иногда лучше чуть том нельзя такой им более всегда конечно всю между это также года году год'));
}

// Текст → список основ (служебные слова выброшены, ё = е)
function sg_search_terms($text) {
	$text = html_entity_decode(wp_strip_all_tags(strip_shortcodes((string) $text)), ENT_QUOTES, 'UTF-8');
	// заголовки документов вразрядку: «П О С Т А Н О В Л Е Н И Е» → «ПОСТАНОВЛЕНИЕ»
	$text = preg_replace_callback('/(?<!\p{L})\p{Lu}(?:[ \x{00A0}]+\p{Lu}(?!\p{L})){3,}/u', function ($m) { return preg_replace('/[ \x{00A0}]+/u', '', $m[0]); }, $text);
	$text = str_replace(['ё', 'Ё'], 'е', mb_strtolower($text, 'UTF-8'));
	preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
	$stop = sg_search_stopwords();
	$out = [];
	foreach ($m[0] as $t) {
		if (isset($stop[$t])) continue;
		if (preg_match('/^[а-я]+$/u', $t)) $t = sg_stem_ru($t);
		$len = mb_strlen($t);
		if ($len < 2 && !ctype_digit($t)) continue;
		if ($len < 3) $t .= str_repeat('ъ', 3 - $len);   // короткие основы («ум», «№ 5»): у полнотекстового индекса MySQL минимум 3 символа
		$out[] = $t;
	}
	return $out;
}

/* ---------- Таблица и индексация ---------- */

function sg_search_table() {
	global $wpdb;
	return $wpdb->prefix . 'sg_search';
}

function sg_search_install() {
	if ((int) get_option('sg_search_db_ver') === SG_SEARCH_DB_VER) return;
	global $wpdb;
	$t = sg_search_table();
	$wpdb->query("CREATE TABLE IF NOT EXISTS $t (
		post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		post_date DATETIME NOT NULL,
		title TEXT NOT NULL,
		body MEDIUMTEXT NOT NULL,
		FULLTEXT KEY ft_title (title),
		FULLTEXT KEY ft_all (title, body)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	update_option('sg_search_db_ver', SG_SEARCH_DB_VER, true);
}
add_action('init', 'sg_search_install');

function sg_search_index_post($post) {
	global $wpdb;
	$post = get_post($post);
	if (!$post) return;
	if ($post->post_status !== 'publish' || !in_array($post->post_type, SG_SEARCH_TYPES, true) || post_password_required($post)) {
		$wpdb->delete(sg_search_table(), ['post_id' => $post->ID]);
		return;
	}
	$title = implode(' ', sg_search_terms($post->post_title));
	$body  = implode(' ', sg_search_terms($post->post_excerpt . ' ' . $post->post_content));
	$wpdb->replace(sg_search_table(), ['post_id' => $post->ID, 'post_date' => $post->post_date, 'title' => $title, 'body' => $body]);
}

add_action('save_post', function ($id, $post) {
	if (wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
	sg_search_index_post($post);
}, 20, 2);
add_action('deleted_post', function ($id) { global $wpdb; $wpdb->delete(sg_search_table(), ['post_id' => (int) $id]); });

/* ---------- Запрос ---------- */

// Строка пользователя → выражение BOOLEAN MODE. $all=true — все слова обязательны.
function sg_search_boolean($s, $all = true) {
	$parts = [];
	if (preg_match_all('/"([^"]+)"/u', $s, $m)) {
		foreach ($m[1] as $ph) {
			$t = sg_search_terms($ph);
			if (count($t) > 1) $parts[] = ($all ? '+' : '') . '"' . implode(' ', $t) . '"';
			elseif ($t) $parts[] = ($all ? '+' : '') . $t[0];
		}
		$s = preg_replace('/"[^"]*"/u', ' ', $s);
	}
	// Snowball режет формы одного слова по-разному (урожай → урожа, урожая → урож): для длинных основ
	// принимаем и основу без последней буквы — только как целое слово, чтобы не размывать поиск.
	foreach (array_unique(sg_search_terms($s)) as $t) {
		if (preg_match('/^\d+ъ*$/u', $t))                          $alt = $t;          // числа (№ 125, 2024) — точно
		elseif (mb_strlen($t) >= 5 && preg_match('/^[а-я]+$/u', $t)) $alt = '(' . $t . '* ' . mb_substr($t, 0, -1) . ')';
		else                                                         $alt = $t . '*';
		$parts[] = ($all ? '+' : '') . $alt;
	}
	return implode(' ', $parts);
}

// → [ids на странице, всего найдено]
function sg_search_run($s, $per, $page) {
	global $wpdb;
	$t = sg_search_table();
	foreach ([true, false] as $all) {
		$q = sg_search_boolean($s, $all);
		if ($q === '') return [[], 0];
		$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE MATCH(title, body) AGAINST(%s IN BOOLEAN MODE)", $q));
		if ($total) break;
	}
	if (!$total) return [[], 0];
	$ids = $wpdb->get_col($wpdb->prepare(
		"SELECT post_id FROM $t WHERE MATCH(title, body) AGAINST(%s IN BOOLEAN MODE)
		 ORDER BY (MATCH(title) AGAINST(%s IN BOOLEAN MODE) > 0) DESC,
		          (MATCH(title) AGAINST(%s IN BOOLEAN MODE) * 3 + MATCH(title, body) AGAINST(%s IN BOOLEAN MODE))
		          * (1 + 0.3 / (1 + TIMESTAMPDIFF(YEAR, post_date, NOW()))) DESC, post_date DESC
		 LIMIT %d OFFSET %d", $q, $q, $q, $q, $per, max(0, ($page - 1) * $per)));
	return [array_map('intval', $ids), $total];
}

// Подмена штатного поиска WP: страница поиска, REST/живой поиск — всё, где у запроса есть s.
add_filter('posts_pre_query', function ($posts, $q) {
	if (!get_option('sg_search_ready')) return $posts;   // пока индекс не построен, работает штатный поиск WordPress
	if ($posts !== null || (is_admin() && !wp_doing_ajax()) || !$q->is_search()) return $posts;
	$s = trim((string) $q->get('s'));
	if ($s === '') return $posts;
	$type = $q->get('post_type');
	if ($type && $type !== 'any' && array_diff((array) $type, SG_SEARCH_TYPES)) return $posts;
	$per  = (int) $q->get('posts_per_page') ?: (int) get_option('posts_per_page');
	$page = max(1, (int) $q->get('paged'));
	if ($per < 0) { $per = 1000; $page = 1; }
	[$ids, $total] = sg_search_run($s, $per, $page);
	$q->found_posts = $total;
	$q->max_num_pages = $per ? (int) ceil($total / $per) : 1;
	if ($q->get('fields') === 'ids') return $ids;
	_prime_post_caches($ids);
	return array_values(array_filter(array_map('get_post', $ids)));
}, 10, 2);

/* ---------- Страница «Инструменты → Русский поиск»: сборка индекса без WP-CLI ---------- */

function sg_search_rebuild_step($last, $batch = 200) {
	global $wpdb;
	$types = "'" . implode("','", SG_SEARCH_TYPES) . "'";
	$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($types) AND ID > %d ORDER BY ID LIMIT %d", $last, $batch));
	if ($ids) {
		_prime_post_caches($ids, false, false);
		foreach ($ids as $id) sg_search_index_post((int) $id);
		return ['last' => (int) end($ids), 'done' => count($ids)];
	}
	// конец: убрать снятые с публикации и включить поиск
	$wpdb->query("DELETE s FROM " . sg_search_table() . " s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id AND p.post_status='publish' WHERE p.ID IS NULL");
	update_option('sg_search_ready', 1, true);
	return ['last' => $last, 'done' => 0, 'finished' => true];
}

add_action('admin_menu', function () {
	add_management_page('Русский поиск', 'Русский поиск', 'manage_options', 'sg-search', 'sg_search_admin_page');
});

add_action('wp_ajax_sg_search_rebuild', function () {
	check_ajax_referer('sg_search_rebuild');
	if (!current_user_can('manage_options')) wp_send_json_error();
	sg_search_install();
	$last = (int) ($_POST['last'] ?? 0);
	if ($last === 0) delete_option('sg_search_ready');   // пока идёт пересборка, работает штатный поиск
	wp_send_json_success(sg_search_rebuild_step($last));
});

function sg_search_admin_page() {
	global $wpdb;
	sg_search_install();
	$count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . sg_search_table());
	$ready = (bool) get_option('sg_search_ready');
	$nonce = wp_create_nonce('sg_search_rebuild');
	?>
	<div class="wrap">
		<h1>Русский поиск</h1>
		<p>Статус: <strong><?php echo $ready ? 'включён' : 'выключен (индекс не построен)'; ?></strong>. В индексе записей: <strong id="sg-count"><?php echo $count; ?></strong>.</p>
		<p>Пока индекс не построен, работает штатный поиск WordPress. После сборки поиск включается сам. Новые и изменённые записи попадают в индекс автоматически.</p>
		<p><button class="button button-primary" id="sg-go">Построить индекс заново</button> <span id="sg-msg"></span></p>
	</div>
	<script>
	(function () {
		var btn = document.getElementById('sg-go'), msg = document.getElementById('sg-msg'), cnt = 0;
		function step(last) {
			var fd = new FormData();
			fd.append('action', 'sg_search_rebuild'); fd.append('_ajax_nonce', '<?php echo esc_js($nonce); ?>'); fd.append('last', last);
			fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
				if (!j.success) { msg.textContent = 'Ошибка. Обновите страницу и повторите.'; btn.disabled = false; return; }
				cnt += j.data.done; msg.textContent = 'Проиндексировано: ' + cnt;
				if (j.data.finished) { msg.textContent = 'Готово: ' + cnt + ' записей. Поиск включён.'; btn.disabled = false; return; }
				setTimeout(function () { step(j.data.last); }, 300);
			}).catch(function () { msg.textContent = 'Сбой сети. Повторите.'; btn.disabled = false; });
		}
		btn.addEventListener('click', function () { btn.disabled = true; cnt = 0; msg.textContent = 'Начали…'; step(0); });
	})();
	</script>
	<?php
}

/* ---------- WP-CLI ---------- */

if (defined('WP_CLI') && WP_CLI) {
	/**
	 * Индекс поиска. rebuild [--batch=300] [--sleep=1] — полная пересборка пачками (пауза бережёт MySQL Timeweb);
	 * test <слова> — показать основы и первые 10 результатов.
	 */
	WP_CLI::add_command('sg-search', function ($args, $assoc) {
		global $wpdb;
		$cmd = $args[0] ?? '';
		if ($cmd === 'rebuild') {
			$batch = (int) ($assoc['batch'] ?? 300);
			$sleep = (float) ($assoc['sleep'] ?? 1);
			$types = "'" . implode("','", SG_SEARCH_TYPES) . "'";
			$last = 0; $n = 0; $t0 = microtime(true);
			while ($ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($types) AND ID > %d ORDER BY ID LIMIT %d", $last, $batch))) {
				_prime_post_caches($ids, false, false);
				foreach ($ids as $id) sg_search_index_post((int) $id);
				$last = (int) end($ids); $n += count($ids);
				WP_CLI::log(sprintf('%d записей, %.0f с', $n, microtime(true) - $t0));
				wp_cache_flush();
				if ($sleep > 0) usleep((int) ($sleep * 1e6));
			}
			// снятые с публикации, но оставшиеся в индексе
			$wpdb->query("DELETE s FROM " . sg_search_table() . " s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id AND p.post_status='publish' WHERE p.ID IS NULL");
			update_option('sg_search_ready', 1, true);
			WP_CLI::success("проиндексировано $n");
		} elseif ($cmd === 'test') {
			$s = implode(' ', array_slice($args, 1));
			WP_CLI::log('основы: ' . implode(' ', sg_search_terms($s)) . ' | запрос: ' . sg_search_boolean($s));
			$t0 = microtime(true);
			[$ids, $total] = sg_search_run($s, 10, 1);
			WP_CLI::log(sprintf('найдено %d за %.3f с', $total, microtime(true) - $t0));
			foreach ($ids as $id) WP_CLI::log(get_the_date('Y-m-d', $id) . "\t" . wp_strip_all_tags(get_the_title($id)));
		} else {
			WP_CLI::error('rebuild | test <слова>');
		}
	});
}
