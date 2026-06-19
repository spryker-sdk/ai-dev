# Spryker Actors — Canonical Reference

Every user story MUST name its actor from the real Spryker actor set. Do not invent personas like "user", "person", or "manager" — map them onto one of these. A story MAY use a narrower role name (e.g. "Back Office content manager") **as long as it maps to one canonical actor and names it** (see Story phrasing). The official Spryker docs describe these roles ("customers, Back Office users, agents, merchant, and merchant agent users").

## The canonical actors

| Actor | Who they are | Where they operate | How they authenticate |
|-------|--------------|--------------------|-----------------------|
| **Back Office user** | Internal operator running the shop. This is the umbrella actor — name the **specific ACL role** when the story is role-scoped (e.g. *Back Office content manager*, *Back Office catalog manager*, *Back Office customer-service user*), or just *Back Office user (admin)* for full-access operators. Permissions controlled by ACL roles. | Back Office (Zed) — `http://<backoffice-host>` (host from the `backoffice` application in deploy.dev.yml) | `security-gui/login`, e.g. `admin@spryker.com` |
| **Customer** | Storefront shopper (B2C buyer or B2B company user). | Yves storefront + Glue API | Yves login / Glue access token, e.g. `sonia@acme.com` |
| **Agent** | Customer-service representative who assists customers and can act on their behalf ("agent assist" / customer impersonation). | Yves agent flow | `agent/login` on Yves, e.g. `agent123@spryker.com` |
| **Merchant user** | Staff of **one** merchant. Scoped to a single seller — bound to exactly one merchant via `spy_merchant_user.fk_merchant`. Sees and acts on only that merchant's data. | Merchant Portal | `security-merchant-portal-gui/login`, e.g. `harald@spryker.com` |
| **Merchant Agent** | Marketplace-operator support who acts **across multiple merchants** — not locked to one seller. The merchant-side equivalent of an Agent. | Merchant Portal | `security-merchant-portal-gui/login` |

### Merchant user vs Merchant Agent — the key distinction is **scope**

Both log in through the same Merchant Portal URL; what differs is *how many merchants they can act for*:
- **Merchant user** → exactly **one** merchant (e.g. `harald@spryker.com` operates only "Spryker"; `michele@sony-experts.com` only "Sony Experts"). Each `spy_merchant_user` row ties a user to one `fk_merchant`.
- **Merchant Agent** → **cross-merchant**; assists/switches between many sellers.

So a story written for a Merchant user is always *"for my single merchant"*; a story for a Merchant Agent *spans or switches between merchants*. If a story needs cross-merchant reach, the actor is Merchant Agent, not Merchant user.

## How to choose the actor for a story

1. Ask: *who initiates this action and where?* The "where" (storefront vs Back Office vs Merchant Portal vs API) usually pins the actor.
2. If a story seems to involve "the system" or "the team", rephrase from the perspective of the human who triggers or observes it.
3. If more than one actor is involved (e.g. a Customer triggers something a Back Office user later reviews), split into separate user stories — one actor each.
4. **Confirm against this install when in doubt.** The real users and roles for this environment are visible at `http://<backoffice-host>/user` (users) and `/acl` (roles), where `<backoffice-host>` is resolved from the `backoffice` application in deploy.dev.yml. Use the `spryker-runtime` skill to log in and check rather than assuming a role exists.

## Story phrasing

State the actor explicitly and unambiguously. For a Back Office user, **prefer the specific ACL role** when the story is role-scoped, keeping the canonical actor recognizable:

- ✅ `As a Back Office content manager (Back Office user)` / `As a Back Office user (admin)` / `As a Customer` / `As an Agent assisting a customer` / `As a Merchant user`
- ❌ `As a user` / `As an administrator` (which one?) / `As the system` / `As a content manager` (which actor?)

Pattern for a specialized role: `As a <specific role> (<canonical actor>)` — e.g. `As a Back Office catalog manager (Back Office user)`. Always keep the canonical actor in parentheses so the role stays mapped. If the install has a custom ACL role, confirm it exists at `/acl` (via `spryker-runtime`) before naming it.
