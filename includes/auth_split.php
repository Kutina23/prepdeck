<?php
/** @var string $initial  login | register | forgot | reset */
// register: the school comes first (searchable). ?school=<id> or the school picked on the landing page preselects it.
$preSchool = Tenant::find((int)($_GET['school'] ?? ($_SESSION['visit_school'] ?? 0)));
if ($preSchool && !$preSchool['is_active']) $preSchool = null;
$deps = $preSchool ? rows('SELECT id,name FROM departments WHERE school_id=? AND is_active=1 ORDER BY name', [$preSchool['id']]) : [];
$levels = ['100'=>'100 level','200'=>'200 level','300'=>'300 level','400'=>'400 level','500'=>'500 level','postgraduate'=>'Postgraduate'];
$initial = $initial ?? 'login';
$resetError = $resetError ?? '';                 // reset.php: link expired / already used / wrong token
$resetToken = $resetToken ?? '';                 // reset.php: the token from the link, empty when invalid
$tabs = $initial === 'login' || $initial === 'register';
$scripts[] = 'pw.js';                            // show / hide buttons on the password fields
?><main class="auth-split">
  <aside class="auth-bg" aria-label="PrepDeck">
    <div class="auth-brand">
      <a href="<?= e(url()) ?>" class="auth-logo" aria-label="PrepDeck — back to home">
        <?= logo_svg() ?>
        <span>PrepDeck</span>
      </a>
    </div>
    <div class="auth-panel">
      <span class="auth-eyebrow">Past questions &middot; Flashcards &middot; Study guides</span>
      <p class="auth-headline">Walk into the exam having already seen it.</p>
      <p class="auth-tagline">PrepDeck turns your school&rsquo;s past question papers into flashcards, study guides and games &mdash; sorted by department and semester.</p>
      <svg class="auth-illustration" viewBox="0 0 480 520" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
        <g opacity=".16">
          <circle cx="110" cy="130" r="100" fill="#0F766E"/>
          <circle cx="380" cy="110" r="70" fill="#FF7A1A"/>
          <circle cx="400" cy="380" r="60" fill="#0F766E"/>
          <circle cx="90" cy="370" r="50" fill="#FFD23F"/>
        </g>
        <g stroke="#EAF6F3" stroke-width="2" fill="none" opacity=".08"><circle cx="245" cy="255" r="160"/></g>
        <!-- animated flashcard: gentle bob + checkmark reveal -->
        <g class="fc">
          <rect class="fc-card" x="115" y="270" width="230" height="150" rx="16" fill="#FFFFFF" stroke="#0D2B2E" stroke-width="2.5"/>
          <rect x="145" y="312" width="145" height="11" rx="5.5" fill="#0D2B2E" opacity=".85"/>
          <rect x="145" y="336" width="105" height="11" rx="5.5" fill="#0D2B2E" opacity=".5"/>
          <rect x="145" y="360" width="125" height="11" rx="5.5" fill="#0D2B2E" opacity=".5"/>
          <path class="fc-check" d="M154 396l24 24 42-42" fill="none" stroke="#0F766E" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/>
          <text class="fc-tag" x="145" y="430" font-size="15" fill="#EAF6F3" opacity=".85">Answered correctly</text>
        </g>
      </svg>
    </div>
    <p class="auth-fine">Free for every student &middot; PrepDeck Plus adds Record Lecture, worked answers and PrepDeck AI</p>
  </aside>
  <div class="auth-form-side">
    <div class="card">
      <?php if (!empty($authNotice)): ?><p class="auth-note ok" role="status"><?= e($authNotice) ?></p><?php endif; ?>
      <?php if ($tabs): ?>
      <div class="toggle" role="tablist" aria-label="Switch between sign in and create account">
        <button type="button" class="toggle-tab" data-form="login" aria-selected="<?= $initial === 'login' ? 'true' : 'false' ?>">Sign in</button>
        <button type="button" class="toggle-tab" data-form="register" aria-selected="<?= $initial === 'register' ? 'true' : 'false' ?>">Create account</button>
      </div>
      <?php endif; ?>
      <div class="auth-forms">
        <?php if ($initial === 'login' || $tabs): ?>
        <form id="login" class="auth-form<?= $initial === 'login' ? ' active' : '' ?>" autocomplete="off">
          <header class="card-hd">
            <h1>Welcome back</h1>
            <p class="muted">Sign in to continue to PrepDeck.</p>
          </header>
          <div class="fields">
            <div class="field">
              <label for="login-email">Email address</label>
              <input id="login-email" name="email" type="email" placeholder="you@school.edu" required autocomplete="username">
            </div>
            <div class="field">
              <label for="login-password">Password</label>
              <input id="login-password" name="password" type="password" placeholder="Your password" required autocomplete="current-password">
            </div>
          </div>
          <p class="auth-forgot"><a href="<?= e(url('forgot.php')) ?>">Forgot password?</a></p>
          <p class="err" role="alert"></p>
          <button class="btn pop" type="submit">Sign in</button>
        </form>
        <form id="twofa" class="auth-form" autocomplete="off" hidden>
          <header class="card-hd">
            <h1>Two-step sign-in</h1>
            <p class="muted" id="twofaHelp">Open your authenticator app and enter the 6-digit code for PrepDeck.</p>
          </header>
          <div class="fields">
            <div class="field">
              <label for="twofa-code" id="twofaLabel">Authenticator code</label>
              <input id="twofa-code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123 456" required autocomplete="one-time-code" spellcheck="false">
            </div>
          </div>
          <p class="auth-forgot"><button type="button" class="linkbtn" id="twofaSwap">Use a recovery code instead</button></p>
          <p class="err" role="alert"></p>
          <button class="btn pop" type="submit">Verify and sign in</button>
          <p class="auth-forgot"><button type="button" class="linkbtn" id="twofaBack">Back to sign in</button></p>
        </form>
        <?php endif; ?>
        <?php if ($initial === 'register' || $tabs): ?>
        <form id="register" class="auth-form<?= $initial === 'register' ? ' active' : '' ?>" autocomplete="off">
          <header class="card-hd">
            <h1>Create your student account</h1>
            <p class="muted">For students of any school in Ghana. You must be 18 or older to use PrepDeck AI.</p>
          </header>
          <div class="fields">
            <div class="field">
              <label for="name">Full name</label>
              <input id="name" name="name" type="text" placeholder="Jane Doe" required>
            </div>
            <div class="field">
              <label for="email">Email address</label>
              <input id="email" name="email" type="email" placeholder="you@school.edu" required autocomplete="username">
            </div>
            <div class="field school-pick" style="position:relative">
              <label for="school_q">School</label>
              <input id="school_q" type="search" placeholder="Search: University of Ghana, KNUST, Accra..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="school_list" value="<?= e($preSchool['name'] ?? '') ?>" required>
              <input type="hidden" id="school_id" name="school_id" value="<?= (int)($preSchool['id'] ?? 0) ?: '' ?>" required>
              <div class="ac-list" id="school_list" role="listbox" hidden></div>
              <span class="hint" id="schoolHint" aria-live="polite"><?= $preSchool ? e(trim(($preSchool['city'] ?? '') . ', ' . ($preSchool['region'] ?? ''), ', ')) : '' ?></span>
              <button type="button" class="linkbtn" id="schoolMissing">My school is not listed</button>
            </div>
            <div class="field">
              <label for="department_id">Department</label>
              <select id="department_id" name="department_id" required<?= $preSchool ? '' : ' disabled' ?>>
                <option value="" disabled selected><?= $preSchool ? 'Choose your department' : 'Choose your school first' ?></option>
                <?php foreach ($deps as $d): ?>
                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                <?php endforeach; ?>
                <option value="new">My department is not listed</option>
              </select>
              <input id="department_name" name="department_name" type="text" placeholder="Type your department or programme" hidden maxlength="120">
            </div>
            <div class="field">
              <label for="level">Level / Year of study</label>
              <select id="level" name="level" required>
                <option value="" disabled selected>Select your level</option>
                <?php foreach ($levels as $val => $lbl): ?>
                <option value="<?= e($val) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint" id="levelHint" aria-live="polite"></span>
            </div>
            <div class="field">
              <label for="dob">Date of birth</label>
              <input id="dob" name="date_of_birth" type="date" required>
              <span class="hint" id="dobHint" aria-live="polite"></span>
            </div>
            <div class="field">
              <label for="password">Password (8+ characters)</label>
              <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
            </div>
          </div>
          <p class="err" role="alert"></p>
          <button class="btn pop" type="submit" disabled>Create account</button>
        </form>
        <?php endif; ?>
        <?php if ($initial === 'register' || $tabs): ?>
        <form id="schoolReq" class="auth-form" autocomplete="off" hidden>
          <header class="card-hd">
            <h1>Add my school</h1>
            <p class="muted">Tell us which school to add. We will email you when it is ready, usually within a few days.</p>
          </header>
          <div class="fields">
            <div class="field"><label for="rq_name">School name</label><input id="rq_name" name="name" required maxlength="160"></div>
            <div class="field"><label for="rq_type">Type</label><select id="rq_type" name="type"><?php foreach (Tenant::TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="rq_region">Region</label><select id="rq_region" name="region"><option value="">Choose a region</option><?php foreach (Tenant::REGIONS as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="rq_city">Town or city</label><input id="rq_city" name="city" maxlength="80"></div>
            <div class="field"><label for="rq_email">Your email</label><input id="rq_email" name="email" type="email" required></div>
          </div>
          <p class="err" role="alert"></p>
          <p class="auth-note ok" role="status" hidden></p>
          <button class="btn pop" type="submit">Send request</button>
          <p class="auth-forgot"><button type="button" class="linkbtn" id="schoolReqBack">Back to create account</button></p>
        </form>
        <?php endif; ?>
        <?php if ($initial === 'forgot'): ?>
        <form id="forgot" class="auth-form active" autocomplete="off">
          <header class="card-hd">
            <h1>Reset your password</h1>
            <p class="muted">Enter the email on your account and we will send you a link to choose a new password.</p>
          </header>
          <div class="fields">
            <div class="field">
              <label for="femail">Email address</label>
              <input id="femail" name="email" type="email" placeholder="you@school.edu" required autocomplete="username">
            </div>
          </div>
          <p class="err" role="alert"></p>
          <button class="btn pop" type="submit">Send reset link</button>
        </form>
        <div class="auth-done" id="forgotDone" hidden>
          <header class="card-hd">
            <h1>Check your email</h1>
            <p class="muted" id="forgotMsg"></p>
          </header>
          <p class="note" id="forgotLink" hidden></p>
          <a class="btn pop" href="<?= e(url('login.php')) ?>">Back to sign in</a>
        </div>
        <?php endif; ?>
        <?php if ($initial === 'reset'): ?>
          <?php if ($resetToken === ''): ?>
          <div class="auth-done">
            <header class="card-hd">
              <h1>Reset link problem</h1>
              <p class="muted"><?= e($resetError !== '' ? $resetError : 'That link is not valid.') ?></p>
            </header>
            <a class="btn pop" href="<?= e(url('forgot.php')) ?>">Get a new link</a>
          </div>
          <?php else: ?>
          <form id="reset" class="auth-form active" autocomplete="off">
            <header class="card-hd">
              <h1>Choose a new password</h1>
              <p class="muted">Pick something you have not used here before.</p>
            </header>
            <div class="fields">
              <input type="hidden" name="token" value="<?= e($resetToken) ?>">
              <div class="field">
                <label for="pnew">New password (8+ characters)</label>
                <input id="pnew" name="password" type="password" minlength="8" required autocomplete="new-password">
              </div>
              <div class="field">
                <label for="pnew2">Repeat the new password</label>
                <input id="pnew2" name="password2" type="password" minlength="8" required autocomplete="new-password">
              </div>
            </div>
            <p class="err" role="alert"></p>
            <button class="btn pop" type="submit">Save new password</button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <p class="auth-foot">
        <?php if ($initial === 'login'): ?>
        <a href="<?= e(url('register.php')) ?>">No account? <strong>Create one</strong></a>
        <?php elseif ($initial === 'register'): ?>
        <a href="<?= e(url('login.php')) ?>">Already have an account? <strong>Sign in</strong></a>
        <?php elseif ($initial === 'reset'): ?>
        <a href="<?= e(url('forgot.php')) ?>">Need a new link? <strong>Send one again</strong></a>
        <?php else: ?>
        <a href="<?= e(url('login.php')) ?>">Back to <strong>sign in</strong></a>
        <?php endif; ?>
      </p>
    </div>
  </div>
</main>
