# Tourney – self-hosted tournament system (PHP + MySQL)

A Bracket-style tournament app for LAN parties and small events.

- **Round robin** in one or more groups, then a **final** between the top 2 (or the two group winners).
- **Teams and players**, **courts and match times** worked out automatically.
- **Live scores**: once a match is live, every score change saves by itself. Use the + / − buttons or type the score.
- **Public page** and a **live TV dashboard** that updates every 5 seconds.
- **Accounts** like Dev Board: an installer, email + hashed password, password reset links, and three roles:
  Admin, Organizer, Scorekeeper.
- **Appearance**: your own logo, background image and colors (with a Liftup preset).
- **Discord** (optional, with the Liftup bot): team roles + private channels from one button, match announcements
  to both teams when a match goes live, results and the champion, and a "message both teams" box under every match
  (e.g. the map ban link). See *Discord integration* below.

No Docker needed. See **INSTALL.txt**:

- **Debian server**: run `sudo bash install-debian.sh`. It installs Apache, PHP and MariaDB and sets everything up.
- **Webhotel**: upload the files and open the site. The setup page does the rest.

## Folder layout

```
index.php, tournament.php, dashboard.php   public pages
login.php, reset.php, account.php          sign in, password reset, own account
install.php                                setup (database + first admin)
admin/                                      tournaments, users, appearance
assets/                                     CSS and JavaScript
uploads/                                    logo and background (images only)
app/                                        code + config.php, never served to the web
  schema.sql      database tables (created automatically)
  tournament.php  scheduling, standings, final
  auth.php        accounts, roles, password resets
  theme.php       logo, background, colors
install-debian.sh                           Debian server installer
```

On the Debian server the script puts `app/` outside the website folder: `/var/www/tourney/app`, with the web root at `/var/www/tourney/public`.
On a webhotel, `app/` sits next to the pages and `app/.htaccess` blocks it from the web.

## Discord integration (Liftup bot)

Tourney talks to the [Liftup Discord bot](https://github.com/jauskiii/lanibot26) through the bot's REST API.
Tourney stays the source of truth; the bot owns Discord. Nothing is needed on the Discord side except the bot.

1. On the bot, set `API_TOKEN` to a long random string.
2. In Tourney, open **Discord** (admins only), enter the bot address (e.g. `http://10.0.0.5:8080`) and the same token, then **Save and test**.
3. Give players a **Discord** name on *Teams & players* (username, display name or user ID).
4. **⇅ Sync teams to Discord** creates a role (and a private text + voice channel) per team and gives the roles.

What is sent, and when:

| Event | Where | Setting |
|---|---|---|
| Match set **▶ Live** | both teams' channels, pinging each team role | *When a match goes Live* |
| **📣 Send** under a match | both teams' channels (free text, e.g. map ban link) | always available |
| Match **✓ Finished** | results channel (or the bot's default channel) | *When a match is Finished* |
| Final finished | results channel, `@here` | *Announce the champion* |

Only status changes are announced, never the live score autosaves. If a bot server has the same name as the
court (e.g. `CS2 #1`), its address and password are attached to the message. Every call has a short timeout:
if the bot is down, scores still save and the admin page shows a warning.

**Upgrading an existing install:** copy the changed files below. The `players.discord` column is added
automatically on the next page load (MySQL 8 and MariaDB).

```
app/discord.php        new – the bot client
admin/discord.php      new – settings page
app/db.php             column migration
app/schema.sql         players.discord for new installs
app/bootstrap.php      loads discord.php
app/tournament.php     get_match()
app/admin_actions.php  Discord actions + match status hook
app/views.php          "Discord" in the top bar
admin/tournament.php   Discord card, player Discord names, message box
assets/style.css       styles
```

`Dockerfile` is only for the combined test environment (`liftup-testiymparisto`).
