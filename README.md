<p align="center">
  <img src="art/icon.png" width="128" alt="bobbin icon">
</p>

# Bobbin

Bobbin is a self-hosted webhook inbox and relay. A webhook is an HTTP request that one app sends when
an event occurs. Bobbin gives each integration a stable URL, stores the requests sent to it, and lets
you inspect and replay them without temporarily exposing your development machine to the internet.

Each Bobbin installation serves one organization. Its supported deployment target is that organization's
Laravel Cloud account. It currently provides:

- Named endpoints with public, hard-to-guess capture URLs.
- Stores each request's method, query string, headers, body, and time of receipt.
- Per-endpoint retention from 1 to 365 days, with a 30-day default.
- Optional header and body redaction before a request is stored.
- Replay a selected capture to one configured destination with retries and visible delivery status.

Delivery is at-least-once. A destination may process a request before Bobbin records success, so a
receiver must tolerate duplicates. Bobbin does not yet support sending one capture to several
destinations, changing captured payloads, or vendor-specific signature verification.

## The Easy Way: Scalpels

[Scalpels](https://scalpels.app) is the intended guided installation path. Bobbin is not yet listed
publicly, so use Scalpels when it is available in your account. Bobbin still runs inside your Laravel
Cloud account, using resources you control.

## Run It Yourself

### Prerequisites

- PHP 8.3 or newer, with the extensions required by Laravel and `ext-gmp`.
- Composer 2.
- SQLite support for local development.
- A Laravel Cloud account and the Laravel Cloud CLI for deployment.

Bobbin has no Node, npm, Vite, or frontend build step. Its compiled assets are committed to the
repository.

### Local Setup

1. Get repository access, then clone the repository and enter it. Bobbin is private before launch. If
   your GitHub account does not have access, use Scalpels when Bobbin is available there or ask Artisan
   Build for repository access before continuing.

   ```bash
   git clone https://github.com/artisan-build/bobbin.git
   cd bobbin
   ```

2. Install the locked dependencies, create `.env`, generate an application key, and migrate the local
   SQLite database.

   ```bash
   composer setup
   ```

   A successful run ends with all migrations marked `DONE`.

3. Create the local Owner account. The command prompts for an email, name, password, and password
   confirmation. Passwords must contain at least eight characters.

   ```bash
   php artisan create-admin --local
   ```

   Keep `--local` here. Without it, this Built for Cloud command can select and change a Laravel Cloud
   environment instead of your local database. Success prints `Admin user <email> created.`

4. Attach that Owner to Bobbin's team. The migrations create one team, but Owner creation does not join
   it automatically; without this membership Bobbin returns `403`. This Tinker command is a temporary
   workaround until Bobbin has a bootstrap command. Replace the example email with the address from step
   3.

   ```bash
   php artisan tinker --execute='
   $user = ArtisanBuild\BuiltForCloud\User::query()->where("email", "you@example.com")->firstOrFail();
   $team = App\Models\Team::query()->firstOrFail();
   Illuminate\Support\Facades\DB::table("team_user")->insertOrIgnore([
       "team_id" => $team->id,
       "user_id" => $user->id,
   ]);
   dump("Owner attached to Bobbin team.");
   '
   ```

   You should see `Owner attached to Bobbin team.`

5. Run the test suite.

   ```bash
   composer test
   ```

   The command clears cached configuration, checks formatting, and runs the Pest tests.

6. Start the web app.

   ```bash
   composer dev
   ```

   Leave this terminal open. The server prints its local URL, normally <http://localhost:8000>.

7. In a second terminal, start the database queue worker. Replays remain queued if this process is not
   running.

   ```bash
   php artisan queue:work
   ```

   Leave this terminal open. It should print `Processing jobs from the [default] queue.`

   For automatic retention cleanup during long-running local development, start the scheduler in another
   terminal with `php artisan schedule:work`. Without it, run `php artisan captures:prune` manually when
   you want to remove expired captures.

8. Open <http://localhost:8000/bfc/login> and sign in with the Owner from step 3. Create an endpoint,
   then open **Security settings** to choose retention, redaction, and one replay destination. The URL
   must be absolute public HTTP or HTTPS; delivery counts as succeeded only when the receiver answers
   with a `2xx` response.

   For a predictable local receiver, set `BOBBIN_ALLOW_PRIVATE_DESTINATIONS=true` in local `.env`, run
   `php artisan config:clear`, and start the bundled receiver in another terminal:

   ```bash
   export BOBBIN_LIVE_RECEIVER_OUTPUT=/tmp/bobbin-replay.json
   php -S 127.0.0.1:9001 tests/Live/replay-receiver.php
   ```

   Save `http://127.0.0.1:9001/receive` as the destination. The receiver returns HTTP `202`. Never deploy
   `BOBBIN_ALLOW_PRIVATE_DESTINATIONS=true`.

9. Copy the endpoint's capture URL and send it a request. The URL contains the endpoint's real public
   identifier; do not type the placeholder below literally.

   ```bash
   curl -i -X POST 'http://localhost:8000/hooks/<public-id>?source=readme' \
     -H 'Content-Type: application/json' \
     --data '{"event":"example"}'
   ```

   Bobbin returns `204 No Content` after the request is stored. Open **View captures** to inspect it.
   From the capture page, choose **Queue replay**. Delivery history may show `queued` or `running` briefly,
   then it should show `succeeded` with response status `202`. A failing destination is retried up to four
   times and can take several minutes to become `failed`.

### Deploy To Laravel Cloud

These steps require interactive access to your own Laravel Cloud account.

1. Install the Laravel Cloud CLI and authenticate it in your browser.

   ```bash
   composer global require laravel/cloud-cli
   cloud auth
   ```

   After authentication, the CLI returns to your terminal.

2. Confirm this checkout has a Git remote hosted by a source provider connected to your Laravel Cloud
   account.

   ```bash
   git remote -v
   ```

   You should see a fetch and push URL for `origin`. Push the repository to a supported source provider
   before continuing if no remote is listed.

3. Run the guided first deployment from the repository.

   ```bash
   cloud ship
   ```

   Create and attach a database, and enable the scheduler. The scheduler runs Bobbin's daily retention
   cleanup.

4. Bind the checkout to the application.

   ```bash
   cloud repo:config
   ```

   This writes `.cloud/config.json`, whose organization and application identifiers tell later commands
   which Cloud application this checkout controls. It contains identifiers, not credentials.

5. Create a managed queue named `default`, then make it the environment's default queue. Choose a size
   when prompted, copy the returned queue ID, and use it in the second command.

   ```bash
   cloud managed-queue:create <cloud-environment> --name=default --json --fields=id,name
   cloud managed-queue:set-default <managed-queue-id> --json --fields=id,name,isDefault
   ```

   The CLI first warns that `aws/aws-sdk-php` is missing and offers to install it. Answer **no**: Bobbin
   already installs the SDK through `league/flysystem-aws-s3-v3`, and accepting would unnecessarily
   rewrite `composer.json` and `composer.lock`.

   The second JSON response should contain `"isDefault": true`. Bobbin sends replay jobs through this
   queue name because its jobs do not select another queue; do not create a manually scaled background
   worker.

6. Set Bobbin's app-specific environment variables. Use the primary hostname shown by `cloud ship` for
   `APP_URL`.

   ```bash
   cloud environment:variables <cloud-environment> --json -n --action=set --key=APP_ENV --value=production
   cloud environment:variables <cloud-environment> --json -n --action=set --key=APP_DEBUG --value=false
   cloud environment:variables <cloud-environment> --json -n --action=set --key=APP_NAME --value=Bobbin
   cloud environment:variables <cloud-environment> --json -n --action=set --key=APP_URL --value="https://<your-host>"
   ```

   Let Cloud generate and preserve `APP_KEY`. Never set resource variables for a database, cache, queue,
   or bucket that Laravel Cloud provisions. Cloud injects both credentials and selectors such as
   `DB_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, and `FILESYSTEM_DISK`; setting your own values
   shadows the injected values and breaks the resource.

7. Deploy and wait for the deployment to finish. Replace both placeholders with the names shown by
   `cloud ship`.

   ```bash
   cloud deploy <cloud-application> <cloud-environment> -n --open
   ```

   Without `--no-wait`, this command returns only after the deployment finishes. A successful deployment
   opens the app in your browser.

8. Run the database migrations in the deployed environment. Do this even if the deployment command also
   runs migrations; it is safe and confirms the database is ready.

   ```bash
   cloud command:run <cloud-environment> --cmd="php artisan migrate --force"
   ```

   Each new migration should report `DONE`. An already current database reports that there is nothing to
   migrate.

9. Create the first Owner in the deployed environment. This command intentionally targets Cloud, so
   replace the placeholder with the environment name shown by the CLI and do not add `--local`.

   ```bash
   php artisan create-admin --environment=<cloud-environment>
   ```

   Success prints `Admin user <email> created.`

10. Attach the deployed Owner to the Bobbin team. This is the same temporary bootstrap workaround as
    local step 4. Replace both placeholders.

    ```bash
    cloud tinker <cloud-environment> --code='
    $user = ArtisanBuild\BuiltForCloud\User::query()->where("email", "you@example.com")->firstOrFail();
    $team = App\Models\Team::query()->firstOrFail();
    Illuminate\Support\Facades\DB::table("team_user")->insertOrIgnore([
        "team_id" => $team->id,
        "user_id" => $user->id,
    ]);
    dump("Owner attached to Bobbin team.");
    '
    ```

    You should see `Owner attached to Bobbin team.`

11. Copy the primary hostname opened by `cloud deploy` or shown in the Laravel Cloud dashboard. Request
    `https://<your-host>/up` and expect HTTP `200`. Then sign in at
    `https://<your-host>/bfc/login`, create an endpoint, and repeat the capture example with its HTTPS
    URL. A replay should move from `queued` to `succeeded` after the managed queue processes it.

## Interfaces

The browser UI is the management interface. Endpoint creation, capture inspection, security settings,
replay, and delivery history require a signed-in Built for Cloud user.

Bobbin has no member-management UI. To add another local operator, rerun local step 3 with `--force` and
then repeat step 4 for that email. In Cloud, rerun Cloud step 9 with `--force` and then repeat step 10.

The capture interface is public and accepts any HTTP method at `/hooks/{publicId}`. A successful capture
returns `204`. Bobbin does not expose an app-specific JSON management API or MCP server in this release.

## Configuration

Local defaults come from `.env.example` and the files under `config/`.

| Variable | Local default | Purpose |
| --- | --- | --- |
| `APP_URL` | `http://localhost` | Fallback base URL used outside a web request, such as from a console command. |
| `DB_CONNECTION` | `sqlite` | Stores users, endpoints, captures, deliveries, sessions, cache, and local queue jobs. |
| `SESSION_DRIVER` | `cookie` | Stores local browser sessions in encrypted cookies. |
| `CACHE_STORE` | `database` | Provides cache locks, including replay overlap protection. |
| `QUEUE_CONNECTION` | `database` | Queues replay deliveries locally. |
| `BOBBIN_ALLOW_PRIVATE_DESTINATIONS` | `false` | Allows local or private replay targets only when this is `true` and `APP_ENV=local`. Never enable it in a deployed environment. |

Laravel Cloud may replace resource-related local defaults through its managed environment file. Let it
do so; do not copy these local resource values into Cloud environment variables.

## Verification

Run the complete repository gate before contributing:

```bash
composer ready
```

Run the live capture-to-replay harness when changing the delivery path:

```bash
php tests/Live/run-replay-status.php
```

The harness creates disposable state, starts Bobbin, a local receiver, and a real database queue worker,
then drives capture and replay. It temporarily enables `BOBBIN_ALLOW_PRIVATE_DESTINATIONS`; that flag is
restricted to `APP_ENV=local` and must stay disabled in deployed environments.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| The UI returns `403` after login | Run the team-attachment command from local step 4 or Cloud step 10 for that user. |
| `create-admin` says an Owner already exists | Continue with the existing Owner, or add another Admin by rerunning the command with `--force`, then attach that user to the team. |
| A replay stays `queued` locally | Start `php artisan queue:work`. |
| A replay stays `queued` in Cloud | Confirm a managed queue is attached, set as default, and processing jobs. Do not set `QUEUE_CONNECTION` yourself. |
| A destination is rejected | Use an absolute HTTP or HTTPS URL with a publicly routable host. Credentials, fragments, URL-template braces, unresolved hosts, and local or private addresses are rejected. Redirect responses are not followed and make delivery fail. |
| A local test receiver is rejected | Prefer the live harness. For manual local-only testing, set `BOBBIN_ALLOW_PRIVATE_DESTINATIONS=true`, run `php artisan config:clear`, and never deploy that setting. |
