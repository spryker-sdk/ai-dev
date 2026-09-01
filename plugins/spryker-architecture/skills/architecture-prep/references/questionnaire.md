# Architecture intake questionnaire

53 questions across four levels of depth — 9 at Level 1, 21 at Level 2, 21 at Level 3, 2 at Level 4. A project answers only the levels it needs, and within a level only the questions whose condition is met.

## How to read this

**Depth is chosen per topic, not once for the whole project.** A project can go deep on integrations and stay shallow on volumes.

**Four capability gates** — pricing, availability, payment and assortment — each ask a single question at Level 2 establishing whether the topic is standard, handled elsewhere, or genuinely complex. Their detail is asked at Level 3 only when the gate says it is needed.

**Two kinds of unknown, and they are not the same.** `Too complex to decide now — needs investigation` produces a recommended Solution Design. `Not decided yet` produces an open item with an owner and a decide-by date. Neither is ever silently dropped, and no default is asserted for an element whose answer is missing — it is drawn provisionally and labelled.

**Tables are not walked through in conversation.** They arrive as a pre-filled file with an owner and a return date.

---

# Level 1 — High-level vision

Produces the introduction, the scope, and a system context diagram with named external systems. Every question here is asked on every project.

*9 questions.*

## General

### Q1. Before we ask you anything: is there existing material we should read instead — a requirements document, RFP or tender response, a target-architecture write-up, interface specifications from your back-office systems, or current system diagrams (even a photo of a whiteboard)?

*document*

### Q2. What is this platform called?

*shorttext*

### Q3. In one line each, what are the three to five business outcomes it must deliver — and for each, what is not working today that makes it necessary?

*table*

| Outcome | What is wrong today that drives it |
|---|---|
| "open four new country markets" | "every new market is a six-month project" |
| "let business customers reorder without phoning us" | "our service desk retypes 200 orders a week" |
| "replace the old shop before support ends" | "the vendor stops patching it in March" |

### Q4. Is this a new build, or does it replace or extend something you already run? If it replaces something, name the system being replaced.

*pick*

- New build, nothing to replace
- Replaces a commercial commerce platform (name it)
- Replaces a shop we built ourselves (name it)
- Replaces a shop embedded in our back-office system
- Extends a commerce platform we already run
- Consolidates several existing shops into one
- Replaces part of a system that keeps running alongside indefinitely
- Other — please describe

### Q5. Which of these people and organisations will use the platform? Pick all that apply.

*pick*

- Consumers / end shoppers
- Business buyers ordering for their company
- Budget approvers inside the buying company
- Account managers or agents ordering on a customer's behalf
- Customer service staff
- Merchandisers and content editors
- Order and logistics back-office staff
- Third-party sellers or dealers who run their own catalogue and orders
- Suppliers or drop-ship partners
- System administrators and operations
- Other — please describe

### Q6. Through which channels will people place orders at launch? Pick all that apply.

*pick*

- Web storefront (desktop and mobile web)
- Native mobile app
- A frontend built by us or another vendor calling the platform's APIs
- The customer's own procurement system (their buyer shops in our catalogue and returns the basket to their tool)
- Machine-to-machine order intake from a customer's system (EDI)
- In-store ordering at a till or counter
- Call-centre or field team ordering for a customer
- Partner or distributor portal
- No orders are placed on this platform — catalogue, search, quotes or portal use only
- Other — please describe

### Q7. Which other IT systems does this platform work with? One row per system — we are drawing the boxes here, not the arrows.

*table*

| Column | Example |
|---|---|
| Name / what you call it | "Group ERP", "Webshop PIM" |
| Product or engine | SAP S/4, Payone, Salesforce, Akeneo, in-house |
| What it does for you | one line |
| What it is the master for | product data · prices · stock · customers · orders · invoices · content · media · sellers · none — pick any number |
| Who owns it | you · a partner · a third party |
| How we authenticate with it | API key · OAuth · certificate · VPN · not known yet |
| Test or sandbox instance | yes, available now · yes, from &lt;date&gt; · no · not known yet |
| Notes | anything you want us to know |

### Q8. List the markets, countries or brands the platform serves — one row each — and against each one the phase it arrives in, in your own words (e.g. "Germany / internal go-live", "France / public go-live", "Switzerland / not committed").

*table*

### Q9. Pick the three to five qualities you would actually spend money to protect — not the ones that sound good.

*pick*

- Fast pages for the shopper
- Surviving extreme peaks without failing
- Uptime
- Speed of change (new markets and features quickly, without a project)
- Correctness and freshness of data against the source systems
- Security and protection of customer data
- A small team can operate it
- Running cost
- Cheap upgrades to new platform releases
- Accessibility and legal conformance
- Other — please describe

---

# Level 2 — Core foundation

Adds the building-block view, the runtime flows worth drawing, and quality requirements with real numbers. This is the level most projects should reach.

*21 questions.*

## Hosting

### Q10. Where will the platform run?

*pick*

- The vendor's managed cloud (the standard option)
- Your own cloud account, run by you or a partner
- Another public cloud
- Your own data centre
- Hybrid — part cloud, part on-premises
- Too complex to decide now — needs investigation

## What the platform does not do

### Q11. Which of these will be delivered by a separate product or a separately built application, rather than by the commerce platform itself? Name the product for each one you pick.

*pick*

- The shopper-facing storefront itself (a separately built frontend calling our APIs)
- Search and product listing
- Content and landing pages
- Digital asset management
- Tax calculation
- Payment orchestration
- Customer login and identity
- Personalisation and recommendations
- Product data authoring and enrichment
- Promotions or loyalty
- Other — please describe

## Storefronts and markets

### Q12. If you will run more than one storefront (per market, brand or channel): what must genuinely be the same across them — one catalogue, one customer base, one pool of orders — and what must be separate?

*pick*

- Only one storefront — not applicable
- Everything shared: one catalogue, one customer base, one order pool
- Catalogue shared, customers and orders separate
- Nothing shared — each storefront is its own world
- Other — please describe
- Too complex to decide now — needs investigation
- Not decided yet

## The four capability gates

### Q13. How complicated is pricing? Pick the closest.

*pick*

- Standard — one price per product, per market and currency
- Another system calculates it (name it)
- Negotiated prices or contracts per customer, or a price per seller
- Other — please describe
- Too complex to decide now — needs investigation

### Q14. How does the platform decide whether an item is available to order? Pick the closest.

*pick*

- Not applicable — availability is never shown
- Standard — one stock figure per item, refreshed by import
- Another system owns it (name it)
- Stock per warehouse or location that must be resolved into one answer, or reservations, batches, lead times
- Other — please describe
- Too complex to decide now — needs investigation

### Q15. Where is the shopper's payment handled? Pick the closest.

*pick · asked when Q6 ≠ "no orders"*

- No payment is taken here this phase — invoiced or charged elsewhere
- Standard — one provider covers the methods we need
- Another system takes the money (name it)
- Several providers, money split between sellers, funds held, instalments or credit checks
- Other — please describe
- Too complex to decide now — needs investigation

### Q16. Does every signed-in customer see the same products, or does it depend on who they are? Pick the closest.

*pick*

- Standard — everyone sees the same catalogue
- Another system owns who sees what (name it)
- An agreed catalogue per customer or site, or visibility by contract, licence or region
- Other — please describe
- Too complex to decide now — needs investigation

### Q17. For each market: which payment methods must shoppers be able to use, and how does the shopper interact with each — stay on your pages, embedded form, sent to the provider, or no interaction because it is invoiced?

*table · asked when Q15 ≠ "no payment"*

## Orders

### Q18. Once an order is placed on this platform, which system runs it to completion — payment, picking, shipping, returns?

*pick · asked when Q6 ≠ "no orders"*

- The platform runs the process and the back-office system reports back
- The platform hands the order over immediately and only mirrors its status
- A dedicated order management system runs it
- Split — the platform up to payment, the back-office system from there
- Other — please describe
- Not decided yet

## Integration

### Q19. Does the platform talk to your back-office systems directly, or does everything pass through one integration layer in the middle? Name it if there is one.

*pick · asked when 2+ systems named in Q7*

- Direct point-to-point to each system
- An enterprise service bus or integration platform (name it)
- An event streaming platform (name it)
- A cloud integration service (name it)
- Middleware you built and own yourselves (name it)
- Mixed — middleware for some systems, direct for others
- Other — please describe
- Not decided yet

### Q20. Now the arrows. For each system in Q7 and each kind of data it exchanges, one row. Fill in what you know — leave the rest blank rather than guessing.

*table*

| Column | Options / example |
|---|---|
| System | pre-filled from Q7 |
| What data | pre-filled from what that system is master for, plus anything it only receives |
| Direction | we send · we receive · both |
| How it travels | real-time API · scheduled API · file drop (SFTP/S3) · message queue · manual upload · database link · other |
| Waiting or background | must finish while a user waits · can run in the background |
| How often, how much | "every 10 min, ~50 products" · "nightly full, 1.5M rows" |
| Trigger | what starts it |
| If it is unavailable | what should happen |
| Notes | anything else |

### Q21. Who signs in through your existing company or customer login system rather than with a shop password?

*pick*

- Nobody — accounts are held in the shop
- Shoppers
- Business buyers, using their own company's login
- Back-office staff
- Third-party sellers' users
- Machine-to-machine API clients
- Other — please describe
- Not decided yet

## Business customers and sellers

### Q22. How are your business customers organised, and how much of that must the platform model?

*pick · asked when Q5 includes business buyers*

- Flat company with a list of users
- Company with departments, sites or branches, and user roles
- As above, plus spending limits and approval before an order is placed
- As above, plus quote negotiation before ordering
- Dealers or distributors that have their own sub-branches underneath them
- Other — please describe
- Not decided yet

### Q23. How do new sellers get onto the platform? Pick the closest.

*pick · asked when Q5 includes third-party sellers*

- They register themselves and are live immediately
- They register themselves; your staff approve before they go live
- Your staff create and set up each seller by hand
- Created automatically from another system (name it)
- Migrated in bulk from the platform you run today
- Mixed — bulk migration first, self-service later
- Other — please describe
- Too complex to decide now — needs investigation

## Migration

### Q24. What must be moved out of the old systems before go-live — and roughly how much of it is there today?

*pick · asked when Q4 ≠ new build*

- Customer companies and their sites
- User accounts
- Stored passwords
- Product catalogue
- Price lists and contracts
- Historical orders
- Orders still in flight at cutover
- Content pages and navigation
- Nothing — clean start
- Other — please describe

## Numbers and targets

### Q25. Fill in the size and load figures — one column per phase you named in Q8.

*table*

- 0
- N/A
- -

### Q26. Fill in the targets you have agreed — one column per phase. We have pre-filled our defaults; correct them, and mark any target that is agreed but not yet met with its current value.

*table*

## Constraints inherited from what you already run

### Q27. Which limitations of your existing systems must the new platform live with?

*pick · asked when Q4 ≠ new build*

- None we know of
- Data formats we cannot change
- An interface that only works one way
- A system that can only be reached in batch windows
- Identifiers or codes we cannot restructure
- A release cycle we do not control
- A contract or licence that limits what we may change
- Other — please describe
- Too complex to decide now — needs investigation

## Runtime

### Q28. Which end-to-end journeys are important or risky enough to need a step-by-step diagram?

*pick*

- A shopper places an order and it reaches the back-office system
- Payment authorisation and capture
- Order status and shipment updates coming back
- Product and price import
- Stock check and reservation
- Login handoff from another application
- Approval or quote before an order
- Ordering from the customer's own procurement system
- Return or cancellation
- Seller onboarding and offer publishing
- Other — please describe

## Confirmations (not questions — accept or correct)

### Q29. Here is the context diagram and the container list we derived from your answers, including the commerce model we inferred. Confirm or correct it before we draw anything else.

*confirm*

### Q30. Here are the risks we derived from your answers, each with a proposed likelihood and impact. Correct any rating you disagree with, and add what we have missed.

*confirm*

---

# Level 3 — Design intelligence

Constraints, deployment, decisions, and the detail behind any topic a Level 2 gate flagged as non-standard. Nothing here is asked unless its condition is met.

*21 questions.*

## Behind the pricing gate — *when Q13 ≠ standard*

### Q31. Which price variations must the shop support?

*pick*

- One list price per product, everywhere
- A different price per market and currency
- Both tax-included and tax-excluded prices
- Quantity or scale prices (cheaper per unit above a threshold)
- Prices per customer group or per named price list
- Prices negotiated per individual company or contract
- Prices set independently by each seller in a marketplace
- Time-limited prices with a start and end date (campaigns, promotions)
- A customer-specific discount applied at checkout rather than a stored price
- Prices per unit of measure (per metre, per kilo, per pack) alongside per-item prices
- Other — please describe
- Too complex to decide now — needs investigation
- Not decided yet

### Q32. Where does the price shown on a product page come from?

*pick*

- Held in the platform
- Imported on a schedule from another system
- Asked live from another system while the shopper waits
- Mixed — list price held here, contract price asked live
- Other — please describe
- Too complex to decide now — needs investigation

## Behind the stock gate — *when Q14 ≠ standard and ≠ not applicable*

### Q33. How often does this platform learn that a stock figure has changed?

*pick*

- Live, on every request
- Every few minutes
- Hourly
- Nightly
- Only when someone imports a file
- Other — please describe
- Too complex to decide now — needs investigation

### Q34. How out of date may the availability shown to a shopper be before it causes a problem?

*pick*

- Seconds
- Minutes
- Hours
- A day
- We show bands, not numbers
- We never promise availability until the order is confirmed
- Other — please describe

### Q35. How is your stock organised physically?

*pick*

- One central figure
- One warehouse
- Several warehouses or sites
- Per seller
- Per market or storefront
- Other — please describe
- Too complex to decide now — needs investigation

### Q36. Does where the stock sits change what a shopper can buy?

*pick*

- No — one total, anyone can buy it
- Yes — the shopper's site or region decides
- Yes — reservations or allocations decide
- Other — please describe
- Too complex to decide now — needs investigation

## Behind the assortment gate — *when Q16 ≠ standard*

### Q37. What decides which products a signed-in customer may see?

*pick*

- Everyone sees the same catalogue
- The market or storefront decides it, nothing more
- A catalogue per customer group or per price list
- A named product list per individual company or contract
- A different list per delivery site or location inside the same company
- The list is decided by another system and fetched while the shopper browses
- Sellers decide which customers may see their offers
- Some products are hidden by licence, certification or age (e.g. prescription items, dangerous goods)
- Other — please describe
- Too complex to decide now — needs investigation
- Not decided yet

## Markets and back office — *when Q8 lists more than one market*

### Q38. Must any market's data physically stay inside a particular country or region?

*pick*

- One region, no residency requirement
- One region, EU only
- One region, a named country only
- One or more markets need their own country or region
- A second region for failover only
- Not yet checked with legal
- Other — please describe

### Q39. In which part of the world should the platform run?

*pick*

- Western or Central Europe
- Northern Europe (Nordics)
- United Kingdom
- North America — east
- North America — west
- Middle East
- Asia-Pacific (e.g. Singapore, Japan)
- Australia or New Zealand
- Other — please describe
- Too complex to decide now — needs investigation
- Not decided yet

### Q40. In the back office, must staff be prevented from seeing data belonging to other markets, brands or sellers?

*pick*

- No — all back-office staff may see everything
- Yes — staff are scoped to one market or brand
- Yes — sellers may only see their own data
- Both of the above
- A restriction exists but has not been defined
- Other — please describe
- Not decided yet

### Q41. How different is the business logic between your markets?

*pick*

- Identical everywhere; only text, currency and prices differ
- Small configuration differences only
- One or two markets need genuinely different process steps
- Markets differ so much they are effectively different shops
- Other — please describe
- Not decided yet

### Q42. How is a new market or storefront onboarded?

*shorttext*

## Constraints and delivery

### Q43. Which technical choices are already fixed by your organisation and not open for discussion?

*pick*

- Cloud provider and region
- Hosting must be self-managed or on-premises
- A corporate identity provider for logins
- A specific content delivery network, web application firewall or bot protection
- A specific payment provider
- A specific back-office or product data system that cannot be changed or extended
- A specific monitoring, logging or performance-tracing tool
- Security logs must reach our own security monitoring system
- A specific build and release toolchain
- A specific email or notification provider
- Other — please describe

### Q44. What actually makes that one fixed?

*pick*

- It is written into a contract, licence or group-wide policy we cannot change
- Another department or group IT owns the decision, not this project
- It is required by law, by an auditor, or by a certification we hold
- The product is already bought and paid for
- Changing it would need a budget decision above this project
- Honestly — we could just decide otherwise
- Other — please describe
- Not decided yet

### Q45. Which legal or regulatory obligations apply to this platform?

*pick*

- General data protection rules
- Card payment security rules
- Data must stay in a specific country or region
- Accessibility conformance (state the level)
- Country-specific electronic invoicing
- Sector rules (pharma, food, chemicals, defence, financial services, age-restricted goods)
- Export control or restricted-party screening
- An internal security certification or customer security review
- Audit evidence for every production change
- None beyond standard data protection
- Other — please describe

### Q46. Who builds this platform, and who operates it after go-live?

*pick*

- A partner builds and runs it
- A partner builds it, your team runs it after handover
- Your own team builds and runs it
- A mixed team throughout
- Several partners working in parallel
- …and releases are frozen during peak trading
- …and releases are frozen around financial year end
- Other — please describe

### Q47. Are there periods when nothing may be changed on the live platform?

*pick*

- No freeze periods
- Peak trading season
- Financial year end
- Fixed change windows only
- Customer-specific blackout dates
- Other — please describe

### Q48. Which environments must exist besides development and production?

*pick*

- Staging only
- Staging plus a business acceptance environment
- Plus a performance and load-test environment
- Plus a training environment
- Plus a hotfix or pre-production environment
- Plus a standby environment in a second region
- Development and production only
- Other — please describe

### Q49. How are non-production environments filled with test data?

*pick (multi)*

- Fixed static demo data
- Anonymised production data
- A copy of production as it is
- Generated synthetic data
- The same interfaces as production, pointed at sandboxes
- Created by hand by the team
- They start empty
- Other — please describe
- Not decided yet

## Transition — *when Q4 ≠ new build*

### Q50. How do you move from what you run today to the new platform?

*pick*

- A single switch-over on one date
- Market by market
- Brand by brand
- A pilot customer group first
- Gradually replace capabilities while the old shop stays live
- Other — please describe
- Not decided yet

## Identity — *when Q21 ≠ nobody*

### Q51. The first time someone signs in from your other application and has no account here yet, what should happen?

*pick*

- Reject the login — the account must be created in advance
- Create a plain shopper account automatically
- Create the account and attach it to the right company automatically
- Different rules for staff and for shoppers
- Other — please describe
- Not decided yet

---

# Level 4 — Full

Cross-cutting concepts and glossary. Opt-in depth.

*2 questions.*

## Glossary and release cadence

### Q52. Do you have a glossary of business terms? Please share it.

*document*

### Q53. How often will you take new platform releases after go-live, and what must be true before one may go live?

*pick*

- Continuously, as releases appear
- Quarterly
- Twice a year
- Once a year
- Only when forced to
- Other — please describe
- Not decided yet
