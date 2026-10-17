<?php
// PrepDeck Community: connect and chat with students from any school. Joining and opening it need the account password.
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u); $uid = (int)$u['id'];
$active = 'community'; $title = 'Community'; $scripts = ['e2ee.js', 'community.js'];
$reason = Community::blockedReason($u); $prof = Community::profile($uid);
$state = $reason ? 'closed' : (!$prof ? 'join' : (Community::unlocked($uid) ? 'open' : 'locked'));
if ($state === 'open') Community::touch($uid);
include ROOT . '/includes/dashboard_header.php';
?>
<?php if ($state === 'closed'): ?>
<section class="cm-gate card"><span class="cm-gate-ic" aria-hidden="true"><?= ic('users2') ?></span><h1>PrepDeck Community</h1><p class="note"><?= e($reason) ?></p>
  <a class="btn line" href="<?= e(url('dashboard/')) ?>">Back to my dashboard</a></section>

<?php elseif ($state === 'join'): ?>
<section class="cm-gate card" aria-labelledby="cmj">
  <span class="cm-gate-ic" aria-hidden="true"><?= ic('users2') ?></span>
  <p class="mono">New</p><h1 id="cmj">Join the PrepDeck Community</h1>
  <p class="note">Meet students from every school on PrepDeck. Find people in your field, add them as friends, and message each other to study together.</p>
  <ul class="cm-points"><li><?= ic('shield', 'i') ?>Only friends can message you. You choose who to accept.</li><li><?= ic('lock', 'i') ?>Your email is never shown. People see your display name, school and level.</li><li><?= ic('flag', 'i') ?>Block or report anyone, any time. The PrepDeck team reviews every report.</li></ul>
  <form class="mform" id="cmJoin" autocomplete="off" data-uid="<?= $uid ?>">
    <div class="field"><label for="cmn">Display name</label><input class="in" id="cmn" name="display_name" maxlength="60" value="<?= e($u['full_name']) ?>" required></div>
    <div class="field"><label for="cmb">About you (optional)</label><input class="in" id="cmb" name="bio" maxlength="280" placeholder="e.g. Level 300 Computer Science, revising Data Structures"></div>
    <label class="chk"><input type="checkbox" name="hidden" value="1">Hide me from search (only people I add can find me)</label>
    <div class="field"><label for="cmp">Your PrepDeck password</label><input class="in" id="cmp" name="password" type="password" required autocomplete="current-password"><span class="hint note">The same password you sign in with. It confirms it is really you.</span></div>
    <label class="chk"><input type="checkbox" name="agree" value="1" required>I will be respectful, keep it about study, and not share anyone's private details.</label>
    <p class="err" role="alert"></p>
    <button class="btn" type="submit"><?= ic('users2') ?>Join the community</button>
  </form>
</section>

<?php elseif ($state === 'locked'): ?>
<section class="cm-gate card" aria-labelledby="cml">
  <span class="cm-gate-ic" aria-hidden="true"><?= ic('lock') ?></span>
  <h1 id="cml">Welcome back, <?= e($prof['display_name']) ?></h1>
  <p class="note">For your privacy the community locks when you sign in again or leave it for an hour. Enter your PrepDeck password to open your chats.</p>
  <form class="mform" id="cmUnlock" autocomplete="off" data-uid="<?= $uid ?>">
    <div class="field"><label for="cmu">Password</label><input class="in" id="cmu" name="password" type="password" required autocomplete="current-password" autofocus></div>
    <p class="err" role="alert"></p>
    <button class="btn" type="submit"><?= ic('lock') ?>Unlock community</button>
  </form>
  <p class="note"><a href="<?= e(url('forgot.php')) ?>">Forgot your password?</a></p>
</section>

<?php else: ?>
<div class="cm" id="cm" data-me="<?= $uid ?>" data-esid="<?= e(Community::esid()) ?>" data-max="<?= Community::MAX_FILE ?>">
  <aside class="cm-side" aria-label="Chats and people">
    <div class="cm-me">
      <span class="cm-av" style="--h:<?= crc32((string)$uid) % 360 ?>"><?= e(initials($prof['display_name'])) ?></span>
      <div class="cm-mename"><b><?= e($prof['display_name']) ?></b><small><?= e(Tenant::name() ?: '') ?></small></div>
      <button class="icobtn" type="button" id="cmProfileBtn" aria-label="Community profile and privacy" title="Profile and privacy"><?= ic('gear') ?></button>
      <button class="icobtn" type="button" id="cmLock" aria-label="Lock community" title="Lock"><?= ic('lock') ?></button>
    </div>
    <div class="cm-tabs" role="tablist" aria-label="Community">
      <button type="button" role="tab" data-tab="chats" aria-selected="true">Chats <span class="cm-badge" id="bChats" hidden></span></button>
      <button type="button" role="tab" data-tab="requests" aria-selected="false">Requests <span class="cm-badge" id="bReq" hidden></span></button>
      <button type="button" role="tab" data-tab="find" aria-selected="false">Find people</button>
    </div>
    <form class="cm-find" id="cmFind" hidden role="search">
      <label class="search"><?= ic('search', 'i') ?><span class="vh">Search by name</span><input id="cmQ" type="search" placeholder="Search by name" autocomplete="off"></label>
      <select class="in" id="cmScope" aria-label="Where"><option value="all">Every school</option><option value="dept">My field of study</option><option value="school">My school</option><option value="others">Other schools</option></select>
    </form>
    <ul class="cm-list" id="cmList" aria-live="polite"></ul>
  </aside>
  <section class="cm-main" id="cmMain" aria-label="Conversation">
    <div class="cm-empty" id="cmEmpty"><span class="cm-gate-ic" aria-hidden="true"><?= ic('send') ?></span><h2>Your messages</h2><p class="note">Pick a chat, or go to <b>Find people</b> to connect with students from other schools.</p></div>
    <div class="cm-thread" id="cmThread" hidden>
      <header class="cm-th">
        <button class="icobtn cm-back" type="button" id="cmBack" aria-label="Back to chats"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
        <span class="cm-av" id="thAv"></span>
        <div class="cm-thname"><b id="thName"></b><small id="thSub"></small></div>
        <div class="dropwrap"><button class="icobtn" type="button" data-drop="thMenu" aria-controls="thMenu" aria-expanded="false" aria-label="Chat options"><?= ic('more') ?></button>
          <div class="hdrop" id="thMenu" role="menu" hidden><nav class="droplinks">
            <button type="button" role="menuitem" data-act="profile"><?= ic('eye') ?>View profile</button>
            <button type="button" role="menuitem" data-act="unfriend"><?= ic('users') ?>Remove friend</button>
            <button type="button" role="menuitem" data-act="report"><?= ic('flag') ?>Report</button>
            <button type="button" role="menuitem" data-act="block" class="out"><?= ic('x') ?>Block</button></nav></div></div>
      </header>
      <div class="cm-keys" id="cmKeys" hidden></div>
      <div class="cm-msgs" id="cmMsgs" tabindex="0" aria-label="Messages"></div>
      <div class="cm-upl" id="cmUpl" hidden><span id="cmUplName"></span><span class="cm-uplbar"><i id="cmUplBar"></i></span></div>
      <form class="cm-compose" id="cmCompose">
        <button class="icobtn cm-attach" type="button" id="cmAttach" aria-label="Share a file (end-to-end encrypted)" title="Share a file, end-to-end encrypted"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.6-8.6a3.4 3.4 0 0 1 4.8 4.8l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4l7.9-7.9"/></svg></button>
        <input type="file" id="cmFile" hidden>
        <label class="vh" for="cmText">Message</label>
        <textarea id="cmText" rows="1" maxlength="<?= Community::MAX_MESSAGE ?>" placeholder="Write a message" required></textarea>
        <button class="btn cm-send" type="submit" aria-label="Send"><?= ic('send') ?></button>
      </form>
    </div>
    <div class="cm-person" id="cmPerson" hidden></div>
  </section>
</div>
<template id="tpl-cmprofile"><form class="card mform" id="cmProfile"><h2>Community profile</h2>
  <div class="field"><label for="pfn">Display name</label><input class="in" id="pfn" name="display_name" maxlength="60" value="<?= e($prof['display_name']) ?>" required></div>
  <div class="field"><label for="pfb">About you</label><input class="in" id="pfb" name="bio" maxlength="280" value="<?= e((string)$prof['bio']) ?>"></div>
  <label class="chk"><input type="checkbox" name="discoverable" value="1"<?= $prof['discoverable'] ? ' checked' : '' ?>>Show me in search</label>
  <p class="err" role="alert"></p><button class="btn" type="submit">Save profile</button>
  <hr style="border:0;border-top:1px solid var(--line);margin:8px 0">
  <h3>Blocked people</h3><ul class="cm-blocked" data-blocked><li class="note">Loading…</li></ul>
  <hr style="border:0;border-top:1px solid var(--line);margin:8px 0">
  <h3>Leave the community</h3><p class="note">Removes your profile, friends and requests, and clears your chats on your side. You can join again later.</p>
  <div class="inline" style="flex-wrap:nowrap"><label class="vh" for="pfl">Password</label><input class="in" id="pfl" type="password" placeholder="Your password" autocomplete="current-password" data-leave-pw><button class="btn sm danger" type="button" data-leave>Leave</button></div>
</form></template>
<?php endif; ?>
<?php include ROOT . '/includes/dashboard_footer.php'; ?>
