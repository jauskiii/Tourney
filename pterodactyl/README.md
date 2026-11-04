# Tourney Pterodactylissa

Tourney ajetaan Pterodactylissa valmiilla **nginx + PHP** -munalla ([Ym0T/pterodactyl-nginx-egg](https://github.com/Ym0T/pterodactyl-nginx-egg)). Tietokantana toimii Pterodactylin oma **Databases**-ominaisuus. Omaa imagea tai munaa ei tarvita.

Tarvittavat kolme asiaa:

1. Tourneylle oma GitHub-repo (tai tiedostot ladattuna käsin).
2. Tietokanta Pterodactylin Databases-välilehdeltä.
3. **Neljä nginx-sääntöä.** Nginx ei lue Tourneyn `.htaccess`-tiedostoja, joten ilman sääntöjä `app/`-kansio näkyisi verkkoon.

## 1. Koodi GitHubiin

Tee esimerkiksi repo `jauskiii/tourney` ja pushaa tämän kansion sisältö niin, että `index.php` on repon juuressa. `.gitignore` pitää jo valmiiksi poissa tiedoston `app/config.php` (tietokannan salasana) ja ladatut kuvat. Repo voi siis olla julkinen.

## 2. Tietokanta

**Admin, kerran:** valitse Admin → **Databases** → *Create New* ja lisää tietokantapalvelin (MariaDB/MySQL, jonka käyttäjä saa luoda tietokantoja). Jos tietokantapalvelinta ei vielä ole, sen voi asentaa noden koneelle (`apt install mariadb-server`). Toinen vaihtoehto on ajaa MariaDB omana Pterodactyl-serverinään.

**Serveri:** kun serveri on luotu (vaihe 3), avaa Admin → Servers → *serveri* → **Build Configuration**. Aseta *Database Limit* vähintään arvoon 1. Valitse sitten serverin omasta paneelista **Databases** → *New Database* → nimi `tourney`. Paneeli näyttää osoitteen (*Endpoint*, esim. `10.0.0.5:3306`), tietokannan nimen (`s3_tourney`), käyttäjän ja salasanan. Tarvitset niitä vaiheessa 5.

## 3. Serveri

1. Lataa muna `egg-nginx-v3.json` [Ym0T:n reposta](https://github.com/Ym0T/pterodactyl-nginx-egg) ja tuo se: Admin → Nests → **Import Egg**.
2. Luo serveri tällä munalla. Pääallokaation portista tulee Tourneyn osoite (esim. `http://10.0.0.5:8090`). RAMiksi riittää 256–512 MB.
3. Aseta Startup-välilehdellä:
   - **PHP VERSION:** 8.3 (tai 8.4). Valitse samalla vastaava Docker-image. Tourney tarvitsee vähintään PHP 8.1:n.
   - **GIT_ADDRESS:** `https://github.com/jauskiii/tourney.git`
   - **GIT_STATUS:** `1`
4. Käynnistä serveri. Muna kloonaa repon `www`-kansioon ja ajaa `git pull` jokaisessa uudelleenkäynnistyksessä, joten päivitykset menevät tuotantoon pushaamalla.

Ilman Gitiä: lataa tämän kansion tiedostot File Managerilla tai SFTP:llä kansioon `www/`.

## 4. Nginx-säännöt (tärkeä)

1. Avaa File Manager ja tiedosto `nginx/conf.d/default.conf`.
2. Liitä tiedoston [`nginx-tourney.conf`](nginx-tourney.conf) sisältö `server { … }`-lohkon sisään, **ennen** riviä `location ~ \.php$`.
3. Käynnistä serveri uudelleen.
4. **Tarkista:** osoitteen `http://<osoite>/app/config.php` pitää antaa **404**. Jos se antaa jotain muuta, säännöt eivät ole voimassa.

Tarkista sama myös munan päivitysten jälkeen. Munan automaattipäivitys koskee `nginx/`-kansiota, ja on mahdollista, että se korvaa tiedoston `default.conf`.

| Sääntö | Estää |
|---|---|
| `/app/` | koodin, tiedoston `config.php` (tietokannan salasana) ja istuntotiedostot |
| `/uploads/` | koodin ajamisen ja näyttämisen ladattujen kuvien kansiossa |
| piilotiedostot | tiedostot `.git`, `.htaccess` ja `.env` |
| `.sql .md .txt .sh`, `Dockerfile`, `/pterodactyl/` | asennus- ja dokumenttitiedostot |

Säännöt on testattu nginx + PHP-FPM -ympäristössä, jossa kansiorakenne on sama kuin munassa.

## 5. Asennus selaimessa

1. Avaa Tourneyn osoite, jolloin asennussivu aukeaa.
2. **Vaihe 1, tietokanta:** käytä vaiheen 2 tietoja. *Host* on Endpointin IP (**ei** `localhost`, koska tietokanta ei ole samassa kontissa). *Port* on Endpointin portti. Lisäksi tarvitaan nimi, käyttäjä ja salasana. Asennus kirjoittaa tiedoston `www/app/config.php` ja luo taulut.
3. **Vaihe 2:** luo admin-tunnus. Asennus kirjaa sinut sisään.

Sähköposti ei yleensä lähde Pterodactyl-kontista. Unohtuneen salasanan palautuslinkin saa siksi admin luotua **Users**-sivulta.

## 6. Kytkentä bottiin

1. Valitse Tourneyssa **Discord**. *Bot address* on botin Pterodactyl-allokaatio, esim. `http://10.0.0.5:8080`. *API token* on botin `API_TOKEN`. Valitse **Save and test**.
2. Jos botti ja Tourney ovat samalla nodella eikä noden IP vastaa kontin sisältä, kokeile Pterodactylin Docker-verkon yhdyskäytävää: `http://172.18.0.1:<botin portti>`.

## Päivitykset ja varmuuskopio

- **Koodi:** push GitHubiin ja serverin uudelleenkäynnistys. Tietokannan uudet sarakkeet lisätään automaattisesti.
- **Data:** tietokanta (Pterodactylin Databases → tietokantapalvelimen varmuuskopio) sekä kansio `www/uploads/` (logo ja taustakuva). Tiedosto `www/app/config.php` kannattaa ottaa talteen, jos serveri asennetaan uudelleen.
