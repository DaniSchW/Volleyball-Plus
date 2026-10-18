<?php
/**
 * @var list<array{key: string, label: string, ok: bool, required: bool}> $checks
 * @var bool $passes
 * @var bool $codeReady
 * @var string $codePath
 * @var list<string> $errors
 * @var array<string, mixed> $old
 * @var array<string, string> $defaults
 */
$t = fn (string $key, array $replace = []) => e(__("install.{$key}", $replace));
$value = fn (string $field) => e((string) ($old[$field] ?? $defaults[$field] ?? ''));
$mailer = $old['mail_mailer'] ?? $defaults['mail_mailer'];
$envWritable = collect($checks)->firstWhere('key', 'env_writable')['ok'] ?? true;

ob_start();
?>
<h1><?= $t('heading') ?></h1>
<p><?= $t('intro') ?></p>

<?php if ($errors !== []) { ?>
<section class="errors" role="alert">
  <h2><?= $t('errors_heading') ?></h2>
  <ul><?php foreach ($errors as $error) { ?><li><?= e($error) ?></li><?php } ?></ul>
</section>
<?php } ?>

<section>
  <h2><?= $t('requirements') ?></h2>
  <ul class="checks">
    <?php foreach ($checks as $check) { ?>
    <li>
      <span><?= e($check['label']) ?> <small class="help">(<?= $check['required'] ? $t('required') : $t('optional') ?>)</small></span>
      <strong class="<?= $check['ok'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') ?>"><?= $check['ok'] ? $t('ok') : $t('missing') ?></strong>
    </li>
    <?php } ?>
  </ul>
  <?php if (! $passes) { ?><p class="bad" style="margin-top:12px"><?= $t('requirements_failed') ?></p><?php } ?>
  <?php if (! $envWritable) { ?><p class="warn" style="margin-top:12px"><?= $t('env_not_writable') ?></p><?php } ?>
</section>

<form method="post" action="/install" autocomplete="off">
  <section>
    <h2><?= $t('setup_code') ?></h2>
    <?php if ($codeReady) { ?>
      <p><?= $t('setup_code_help', ['path' => $codePath]) ?></p>
    <?php } else { ?>
      <p class="bad"><?= $t('setup_code_failed') ?></p>
    <?php } ?>
    <label for="setup_code"><?= $t('setup_code') ?></label>
    <input id="setup_code" name="setup_code" required autocapitalize="characters" spellcheck="false" placeholder="XXXXX-XXXXX-XXXXX-XXXXX">
  </section>

  <section>
    <h2><?= $t('section_app') ?></h2>
    <label for="app_url"><?= $t('fields.app_url') ?></label>
    <input id="app_url" name="app_url" type="url" required value="<?= $value('app_url') ?>">
  </section>

  <section>
    <h2><?= $t('section_db') ?></h2>
    <p><?= $t('section_db_help') ?></p>
    <div class="row">
      <div><label for="db_host"><?= $t('fields.db_host') ?></label><input id="db_host" name="db_host" required value="<?= $value('db_host') ?>"></div>
      <div><label for="db_port"><?= $t('fields.db_port') ?></label><input id="db_port" name="db_port" inputmode="numeric" required value="<?= $value('db_port') ?>"></div>
    </div>
    <label for="db_database"><?= $t('fields.db_database') ?></label>
    <input id="db_database" name="db_database" required value="<?= $value('db_database') ?>">
    <label for="db_username"><?= $t('fields.db_username') ?></label>
    <input id="db_username" name="db_username" required value="<?= $value('db_username') ?>">
    <label for="db_password"><?= $t('fields.db_password') ?></label>
    <input id="db_password" name="db_password" type="password" autocomplete="new-password">
  </section>

  <section>
    <h2><?= $t('section_mail') ?></h2>
    <label class="radio"><input type="radio" name="mail_mailer" value="phpmail" <?= $mailer === 'phpmail' ? 'checked' : '' ?>> <?= $t('mail_phpmail') ?></label>
    <label class="radio"><input type="radio" name="mail_mailer" value="smtp" <?= $mailer === 'smtp' ? 'checked' : '' ?>> <?= $t('mail_smtp') ?></label>
    <label for="mail_from_address"><?= $t('fields.mail_from_address') ?></label>
    <input id="mail_from_address" name="mail_from_address" type="email" required value="<?= $value('mail_from_address') ?>">
    <details <?= $mailer === 'smtp' ? 'open' : '' ?>>
      <summary class="help"><?= $t('mail_smtp') ?></summary>
      <div class="row">
        <div><label for="smtp_host"><?= $t('fields.smtp_host') ?></label><input id="smtp_host" name="smtp_host" value="<?= $value('smtp_host') ?>"></div>
        <div><label for="smtp_port"><?= $t('fields.smtp_port') ?></label><input id="smtp_port" name="smtp_port" inputmode="numeric" value="<?= $value('smtp_port') ?>"></div>
      </div>
      <label for="smtp_scheme"><?= $t('fields.smtp_scheme') ?></label>
      <select id="smtp_scheme" name="smtp_scheme">
        <?php foreach (['smtp', 'smtps'] as $scheme) { ?>
        <option value="<?= $scheme ?>" <?= ($old['smtp_scheme'] ?? $defaults['smtp_scheme']) === $scheme ? 'selected' : '' ?>><?= $t("smtp_schemes.{$scheme}") ?></option>
        <?php } ?>
      </select>
      <label for="smtp_username"><?= $t('fields.smtp_username') ?></label>
      <input id="smtp_username" name="smtp_username" value="<?= $value('smtp_username') ?>">
      <label for="smtp_password"><?= $t('fields.smtp_password') ?></label>
      <input id="smtp_password" name="smtp_password" type="password" autocomplete="new-password">
    </details>
  </section>

  <section>
    <h2><?= $t('section_tenant') ?></h2>
    <label for="tenant_name"><?= $t('fields.tenant_name') ?></label>
    <input id="tenant_name" name="tenant_name" required maxlength="120" value="<?= $value('tenant_name') ?>">
    <label for="tenant_slug"><?= $t('fields.tenant_slug') ?></label>
    <input id="tenant_slug" name="tenant_slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="64" spellcheck="false" value="<?= $value('tenant_slug') ?>">
    <p class="help"><?= $t('slug_help', ['url' => $defaults['app_url']]) ?></p>
  </section>

  <section>
    <h2><?= $t('section_admin') ?></h2>
    <p><?= $t('section_admin_help') ?></p>
    <div class="row" style="grid-template-columns: 1fr 1fr">
      <div><label for="admin_first_name"><?= $t('fields.admin_first_name') ?></label><input id="admin_first_name" name="admin_first_name" required maxlength="80" value="<?= $value('admin_first_name') ?>"></div>
      <div><label for="admin_last_name"><?= $t('fields.admin_last_name') ?></label><input id="admin_last_name" name="admin_last_name" required maxlength="80" value="<?= $value('admin_last_name') ?>"></div>
    </div>
    <label for="admin_gender"><?= $t('fields.admin_gender') ?></label>
    <select id="admin_gender" name="admin_gender" required>
      <?php foreach (['male', 'female'] as $gender) { ?>
      <option value="<?= $gender ?>" <?= ($old['admin_gender'] ?? '') === $gender ? 'selected' : '' ?>><?= $t("genders.{$gender}") ?></option>
      <?php } ?>
    </select>
    <label for="admin_email"><?= $t('fields.admin_email') ?></label>
    <input id="admin_email" name="admin_email" type="email" required value="<?= $value('admin_email') ?>">
  </section>

  <button type="submit" <?= $passes ? '' : 'disabled' ?>><?= $t('submit') ?></button>
</form>
<?php
$content = ob_get_clean();
$title = __('install.title');
include __DIR__.'/layout.php';
