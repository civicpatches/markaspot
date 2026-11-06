# Mark-a-Spot AI

Shared AI client, provider configuration and AI-assisted features (PII name
detection, sentiment and text analysis, embeddings and duplicate detection,
assist) for Mark-a-Spot.

## Sovereign provider

An operator can enforce IONOS for all profile AI requests by setting
`MARKASPOT_AI_SOVEREIGN=ionos` in the PHP process environment. Alternatively,
set `$settings['markaspot_ai_sovereign'] = 'ionos';` in `settings.php`, following
the profile's operator settings convention. A non-empty environment value wins
over the settings value. An unknown non-empty value blocks AI requests.
Drupal configuration and form submissions cannot disable this policy.

The enforced provider is the existing `providers.ionos` entry in
`markaspot_ai.settings`. Its `api_url` must be
`https://openai.inference.de-txl.ionos.com/v1`. A different URL fails closed.
The final AI HTTP destination is checked and redirects are disabled. Generic
`MARKASPOT_AI_API_URL`, `MARKASPOT_VISION_API_URL`, provider selections, and
per-call model overrides cannot redirect content in this mode.

Use `IONOS_AI_API_KEY`, `MARKASPOT_AI_IONOS_KEY`, or
`markaspot_ai.settings:providers.ionos.api_key` for IONOS credentials. Generic
AI keys and Vision keys are deliberately ignored in sovereign mode so that
credentials belonging to another provider are not sent to IONOS.

Models are configuration, not code defaults. The separate
`markaspot_ai.sovereign` object applies only while the policy is enforced:

| Key | Shipped default | Purpose |
| --- | --- | --- |
| `chat_model` | `mistralai/Mistral-Small-24B-Instruct` | Text analysis, name detection, spam, assist, mail categorization, provisioning |
| `vision_model` | `mistralai/Mistral-Small-24B-Instruct` | Vision and image-bearing chat/assist |
| `embedding_model` | `BAAI/bge-m3` | Multilingual embeddings, 1024 dimensions |

The defaults follow the IONOS [Mistral Small documentation](https://docs.ionos.com/cloud/ai/ai-model-hub/models/llms/mistral-small-24b)
and [BGE M3 documentation](https://docs.ionos.com/cloud/ai/ai-model-hub/models/embedding-models/bge-m3),
checked again on 2026-11-03. IONOS explicitly uses the shipped model identifier
in its Vision and base64 image examples and links its underlying model to
Mistral Small 3.2 24B Instruct 2506. There is no documented need to switch to a
different Vision identifier. **Open acceptance item:** verify the default
model's image capability live with IONOS credentials, including the privacy
verdict and structured response. Documentation alone does not prove live
availability, credentials or output quality. An empty model blocks that feature
before any AI-provider HTTP request; independent Blur preprocessing can still run. Existing model vectors must be regenerated when changing embedding
models; an identity-only relabel is not a migration between vector spaces.

Update `markaspot_ai_update_11904()` installs this new configuration only when
absent and preserves deliberately empty model slots. Existing installs must
run the normal reviewed database updates before activation. Configuration
exports must retain `markaspot_ai.sovereign`; importing a configuration set
without this object can remove it and block the affected features. No update
changes the operator setting or existing provider configuration.

Local NLP (Ollama) and its PII fallback remain blocked. The self-hosted
OpenCV/FastAPI Blur service, operated by the platform operator, is an allowed
image privacy service, not a language-model provider. Configured or required
blur preprocessing continues through the existing Blur URL/key resolution,
including `MARKASPOT_BLUR_URL`, without an IONOS redirect or a new allowlist.
HTTP redirects are disabled in sovereign mode, also for optional configured
blur. Vision screening, Assist photos, mail photos and imported-media
preprocessing use this same service. A failed required blur still blocks
analysis. When the IONOS Vision model is missing, preprocessing runs but the
Vision analysis fails closed and an already held upload remains unpublished.
Imported-media optional enrichment retains its existing publication policy;
this change does not add a model requirement to blur-only imports.
Failed name screening retains the existing unpublished review hold. Logs for
policy rejections contain no submitted text, image bytes, credentials, or
configured endpoint value.

The AI settings form locks both provider selectors and displays "fixed by the
operator". Vision displays the effective IONOS endpoint and model and locks
its dormant connection fields. `mas:health` includes **AI provider sovereignty**
with per-feature provider and blocked status. Blur shows its resolved host
and source (environment or configuration). An administrator-configurable Blur
destination, a missing destination, or an invalid HTTP URL produces a warning
in sovereign mode. URL credentials, paths and
queries are not displayed. The Vision ENV check still validates Blur wiring
when AI image analysis is enabled; only dormant Vision connection checks are
skipped. Local NLP is reported as blocked. A content feature pointing to a
non-IONOS endpoint produces an error; invalid sovereign settings, missing
IONOS credentials, missing models and blocked NLP produce a warning. This is a local configuration check, not an external API availability
or credential validation probe.

With the operator setting absent, existing providers, model selection,
endpoint overrides, and fallback behavior remain unchanged. This profile
policy does not cover direct Nuxt onboarding requests or independent cloud
services. Those require separate work before claiming platform-wide enforcement.
This policy does not configure server environments or provision credentials.

## Required blur preprocessing

Photos can pass a blur service (faces and licence plates) before any vision
or AI provider sees them. The site switch
`markaspot_vision.settings.enable_blur_preprocessing` ships as `false`, and the
blur service is not part of the open-source release. The environment variable
`MARKASPOT_BLUR_REQUIRED` decides whether the switch is authoritative. Vision
analysis, the screening of imported and mail photos and the staff photo tools
(attribute filling, description from photo, Assist) read the same parser,
`Drupal\markaspot_ai\Utility\BlurPolicy`.

| `MARKASPOT_BLUR_REQUIRED` | Mode | Behaviour |
|---|---|---|
| unset, `MARKASPOT_BLUR_URL` set in the environment | `auto-required` | Blur is mandatory and fails closed. The site switch and the configured blur URL are ignored. |
| unset, no `MARKASPOT_BLUR_URL` | `auto-unprotected` | The site switch decides, as before. |
| `1`, `true`, `yes`, `on` or an unrecognised value | `strict` | Blur is mandatory. Without `MARKASPOT_BLUR_URL` and a key (`MARKASPOT_BLUR_API_KEY`, legacy `AI_API_KEY`) no photo is sent. |
| `0`, `false`, `no`, `off` | `off` | Deliberate opt-out: the site switch decides. |

Only the environment signals a hosted stack. A blur URL in configuration and
the legacy `VISION_BLUR_URL` never switch to `auto-required`. While blur is
mandatory, photos stay unpublished until screening has completed without a
privacy hold, even when `require_ai_screening` is disabled.

The health check **Vision ENV overrides missing** (`vision_env_drift`)
reports the resolved mode. It reports an error when blur is mandatory but
`MARKASPOT_BLUR_URL` or the key is missing from the environment, whether or
not AI image analysis is enabled, because no photo can be analysed then. In
the other modes it checks the site switch and the configured blur URL when AI
image analysis is enabled.

### Upgrade note for self-hosted installations

- **Without `MARKASPOT_BLUR_URL` in the environment:** no change. The mode is
  `auto-unprotected` and the site switch keeps deciding. An unprotected
  installation with an AI provider sees an advisory (log entry at most once a
  day, status report row, message on the AI and vision settings forms).
- **With `MARKASPOT_BLUR_URL` in the environment:** behaviour change. Blur
  becomes mandatory and the site switch is ignored, also when it is off, and
  the blur URL comes from the environment only. Make sure the blur service is
  reachable and `MARKASPOT_BLUR_API_KEY` is set, or the health check reports
  an error and photos are not analysed. To keep the previous behaviour, set
  `MARKASPOT_BLUR_REQUIRED=0` explicitly.
- **Hosted stacks** should set `MARKASPOT_BLUR_REQUIRED=1`, so that a stack
  without a blur URL fails loudly instead of silently sending unblurred
  photos.

No configuration is changed and no update hook runs for this policy.
