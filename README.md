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
- **Map ban links per team**: every match has its own link for each team. Each team gets only its own link,
  on its own team channel (with the Live message or with one button).
- **Self-registration** (optional, off by default): players open a link, pick their team and type their name and
  Discord username. They land in the team and get the Discord team role. Adding players by hand still works.

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
| **📣 Send** under a match | both teams' channels, or only one team (free text, e.g. map ban link or side info) | always available |
| Match **✓ Finished** | results channel (or the bot's default channel) | *When a match is Finished* |
| Final finished | results channel, `@here` | *Announce the champion* |
| **📣 Send links** in the map ban row | each team only its own link, only on its own team channel | always available |
| Match **▶ Live** with map ban links | each team's Live message carries only its own link | *Put each team's own map ban link in its Live message* |
| A player registers | gives the team role right away (creates the team on Discord if needed) | *Check the Discord name…* (per tournament) |

Only status changes are announced, never the live score autosaves. If a bot server has the same name as the
court (e.g. `CS2 #1`), its address and password are attached to the message. Every call has a short timeout:
if the bot is down, scores still save and the admin page shows a warning.

**Map ban links are private:** a team's link is only ever sent to that team's own channel. If a team has no own
channel on Discord (synced without channels), its link is not sent at all and the admin page says why.
Public pages and the dashboard never show the links.

## Self-registration (optional)

*Teams & players → Self-registration.* Off by default. When open, `register.php?id=<tournament>` lets a player
pick their team and type their name and Discord username. Settings per tournament:

- **Registration code** (optional): players must type it. Use it when the site is reachable from the internet.
- **Max players per team** (default 5, 0 = no limit).
- **Players may create a new team**: only until the schedule is generated (later teams would get no matches).
- **Check the Discord name and give the team role right away** (needs the bot): an unknown name is refused with a
  hint to join the Discord server first; a known name is saved as the person's Discord username.

If an organizer already typed the player's name without a Discord name, that row is filled in instead of a new one.
The same Discord name can't be in two teams. Self-registered players have a ✍ mark on the admin page and can be
edited or removed like any other. **📣 Post link to Discord** posts the link to the channel picked in the card
(*Discord channel for the registration link*), or to the bot's default channel if none is picked. Channels are
picked from a list read from the bot; the results channel on the Discord settings page works the same way.
Collecting names some other way (e.g. a Forms sheet) and typing them in by hand works exactly as before.

**Upgrading an existing install:** copy the changed files below. The new columns are added
automatically on the next page load (MySQL 8 and MariaDB).

```
register.php           new – self-registration page
app/discord.php        new – the bot client
admin/discord.php      new – settings page
app/db.php             column migration (players.discord, matches.mapban1/2, tournaments.reg_* incl. reg_channel)
app/schema.sql         the same columns for new installs
app/bootstrap.php      loads discord.php
app/tournament.php     get_match()
app/admin_actions.php  Discord actions + match status hook
app/views.php          "Discord" in the top bar, stylesheet version
admin/tournament.php   Discord card, player Discord names, message box, map ban row, registration card
tournament.php         "Register" button when registration is open
assets/style.css       styles
```

`Dockerfile` is only for the combined test environment (`liftup-testiymparisto`).

The self-registration page needs the bot version with `POST /api/members/resolve` to check Discord names.
With an older bot the names are saved as typed and the team role is still given.

## Credits

Tourney and the Liftup Discord integration: **Mikael Riihimäki**.
