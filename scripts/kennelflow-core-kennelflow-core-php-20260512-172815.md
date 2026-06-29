# Plugin Check Report

**Plugin:** KennelFlow Core
**Generated at:** 2026-05-12 17:28:15


## `includes/class-owner-pets.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 142 | 13 | WARNING | WordPress.DB.SlowDBQuery.slow_db_query_meta_query | Detected usage of meta_query, possible slow query. |  |

## `includes/class-compliance-upload-api.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 453 | 79 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;\ltkf_legacy_vet_medical_upload_subdir_hook()&quot;. |  |

## `includes/class-waiver-storage.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 48 | 104 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound | Hook names invoked by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$ltkf_med_subdir_legacy_vet_hook&quot;. |  |

## `includes/class-compliance-rules-engine.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 104 | 18 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $prepared used in $wpdb-&gt;get_results()\n$prepared assigned unsafely at line 101. |  |

## `includes/class-portal-data.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 117 | 18 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 102. |  |
| 200 | 17 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_row()\n$sql assigned unsafely at line 185. |  |
| 247 | 17 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_row()\n$sql assigned unsafely at line 236. |  |

## `includes/class-compliance-retention.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 239 | 11 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $date_expr used in $wpdb-&gt;query()\n$date_expr assigned unsafely at line 229. |  |
| 255 | 11 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $date_expr used in $wpdb-&gt;query()\n$date_expr assigned unsafely at line 229. |  |

## `includes/class-report-card-api.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 144 | 21 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_col()\n$sql assigned unsafely at line 141. |  |
| 369 | 25 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_var()\n$sql assigned unsafely at line 364. |  |

## `includes/class-automated-crm.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 136 | 18 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 131. |  |
| 219 | 18 | WARNING | PluginCheck.Security.DirectDB.UnescapedDBParameter | Unescaped parameter $sql used in $wpdb-&gt;get_results()\n$sql assigned unsafely at line 214. |  |
