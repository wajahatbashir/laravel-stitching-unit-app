# Lumiere Premium — User Guide

Works on phone (installable like an app) and desktop. On a phone you get a **bottom bar** (Home · Orders · **＋** · Expenses · Labour) and a ☰ menu; on desktop a left sidebar.

## 1. Signing in & installing
- Open the site, sign in. Tick *Keep me signed in* on your own phone.
- **Install on phone:** browser menu → *Add to Home screen / Install app* (needs HTTPS or localhost).
- Switch language with the **اردو / EN** button (top right). Change your password under your name → *Profile*.

## 2. The ＋ quick-add button (phone)
Opens shortcuts: New expense · Work entry · Pay worker/advance · New order · New invoice · Customer payment · Stock movement · Investment. You only see what your role allows.

## 3. Working offline (market, no internet)
1. Open the app **once while online** after signing in (it saves the add-forms on your phone).
2. With no internet, use the **＋** forms normally and press **Save**. You'll see *“Saved offline”* and an orange **Offline** badge with a counter.
3. When internet returns the app **syncs automatically** (badge turns blue, then disappears). Nothing is saved twice.
4. Tap the badge to open **Offline sync**: *Waiting* items are queued; *Rejected* items failed validation (e.g. missing order) — read the message, **Discard** and re-enter.
- Offline works for **adding** records. Editing/deleting and opening pages you never visited need internet.
- Receipt photos taken offline are uploaded at sync and **read automatically** then.

## 4. Orders
*Orders → Add new*: order number (auto), date, client, collection, fabric, quantity, rate (billing), due date, status, attachments (designs/samples).
- Filter by client, status, collection, fabric, date; export Excel/PDF.
- Open an order to see **billed amount, material cost, labour cost, cost per piece and profit**, every expense and stitching entry against it, and shortcuts to *Add expense / Work entry / Invoice*.

## 5. Expenses
Choose the type at the top:
| Type | Use for | Notes |
|---|---|---|
| **Order** | laces, organza, shamoz, shameez (lining), embellishment… | **must** pick the order → feeds order cost |
| **Unit** | machines, repairs, furniture, tools | optional *related asset*; extra fields/photos defined by admin |
| **Labour** | food, tea, transport for staff | |
| **Other** | rent, electricity, gas, internet | |

**Receipt upload (bank app screenshots):** attach the screenshot (from the gallery or the camera) → a preview appears and the app reads **amount, date, paid-to, purpose (Description) and transaction ID (Notes)** and fills them in. Works with NayaPay (light/dark; Raast to Meezan, JazzCash, Easypaisa…) and Faysal Bank bill payments. *Always check the values.* On edit you see the saved receipt (tap to enlarge) and can replace or remove it; open any expense (eye icon) for the full-size receipt. Then choose the category and Save. If the amount can't be read you'll be asked to type it. Multi-currency: pick the currency; reports use PKR.

## 6. Labour & staff
**Workers & Staff → Add:** freelancer (piece-rate) or employee (monthly salary), payment cycle (weekly / 2 weeks / monthly). Optional *personal rate card* per garment.

**Daily work (freelancers):** *Work entries → Add* — worker, date, order, garment, pieces. The rate fills from the rate card (editable); amount = pieces × rate. Use **Save & add another** for many entries.

**Salaried staff:** *Monthly salaries → Generate month* creates this month's entry for every active employee (adjust bonus/deduction per person).

**Paying:** open the worker → see **Brought forward, Earned, Paid, Balance** for any period (This week / 14 days / month / custom) with status **Pending / Partially paid / Paid in full**. Enter the amount in *Record payment* (pre-filled with the balance).
- **Partial payment:** pay less — the unpaid remainder stays in the balance and is carried to the next payment automatically.
- **Advance:** choose type *Advance*. The balance goes negative (“Advance to adjust”) and is deducted from future earnings automatically.
- Every worker page exports to Excel/PDF.

## 7. Customers, invoices, payments
- *Invoices → Add*: pick an order to pre-fill the line, edit lines, discount/tax. **PDF** button on the invoice page.
- *Customer payments*: record money received. Pick an **invoice** if the payment is for that invoice; **leave the invoice empty to record an ADVANCE** (money on account).
- **Customer advances — how they work**
  1. Record the advance as a customer payment *without* an invoice. It is held as the customer's *advance balance* (shown on the customer page, customer list and dashboard).
  2. **When creating an invoice:** if the customer has an advance, a box *"Deduct customer advance from this invoice"* appears showing the amount available. Tick it, enter how much to deduct (pre-filled with the maximum) and save. If you don't tick it, the invoice works exactly as before.
  3. **On an existing invoice:** open it → *Apply advance*.
  4. One advance can pay several invoices and one invoice can use several advances; the unused remainder stays available. Each use is recorded (date, amount, which payment) and listed on the invoice and on the customer page. *Remove* on an invoice gives the money back to the advance balance.
  5. An advance that is already applied can't be deleted, or edited to less than what was applied, until it is removed from those invoices.
- Customer page shows: outstanding receivable (invoices still unpaid), advance held, every payment with where it was applied, and a link to the full ledger report.

## 8. Inventory
*Inventory → items* (laces, fabric…) with a low-stock level. *Stock movements*: in (bought) / out (used, optionally for an order). Admins are notified when stock drops to the minimum.

## 9. Assets & investments
- **Assets:** machines (number, brand, model, serial, cost, vendor, status, photos), furniture, others. The asset page lists its repair expenses.
- **Investors / Investments:** each deposit or withdrawal with *Bank* or *Cash in hand*. These drive the cash/bank balances.

## 10. Reports (Excel + PDF)
Order cost & profit · Expenses · Labour payable/paid · Worker statement · Customer ledger · Cash/bank & investments · Profit & Loss · Balance sheet · Asset register · Inventory stock. Use the date and other filters, then export.

## 11. If you are a worker with a login
You see only what the admin enabled — typically add work/expense entries and **My ledger** (your earnings, payments, balance). You can't see other people's records.

## 12. Getting around (header, search, profile, dark mode)
- **Search:** the box at the top (phone: the magnifier icon) finds orders, customers, invoices, workers, expenses, vendors and assets as you type. On a computer press **/** to jump to it, use ↑ ↓ and **Enter**. It only shows what your role may see, and needs internet.
- **New (＋):** on a computer the **New** button in the header lists every quick-add action; on a phone use the big **＋** button in the bottom bar.
- **Bell:** shows your latest notifications without leaving the page; **Mark all read** clears the badge.
- **Your avatar (top right):** My profile · My ledger (if you are a worker) · Settings (admins) · **Language** English/اردو · **Appearance** Light / Dark / Auto · Log out.
- **Profile page:** *Profile* (name, phone, **photo upload**), *Security* (change password), *Preferences* (language, appearance), *My activity* (the last changes you made). It also shows when you last signed in.
- **Dark mode:** choose *Dark* (or *Auto* to follow your phone). It is remembered on each device separately.
- **Pages:** each page shows a breadcrumb trail, a title and its main buttons (Export, Add new) at the top. Tables keep their column headings visible while you scroll.
- **Dashboard charts:** *Revenue vs costs* for the last 6 months (hover or tap a month for the numbers; **Table** shows them as a list) and *Where the money went* this month, including wages.

## 13. Dashboard periods
Under *Money position* (always **today**) pick **This week / This month / This year / Custom**. The tiles (revenue, payments received, costs, profit, orders received, wages paid), both charts and the Expenses card then show that period.
- **‹ ›** moves to the previous period (and back); each tile shows ▲/▼ % compared with the equally long period before it.
- **Custom:** choose *From* and *To* and press **Apply**, or tap a shortcut (Today, Last 7 / 30 / 90 days, Last 12 months). The address of the page can be bookmarked or shared.
- The chart bars adapt: days for a week, weeks for a month, months for a year or long custom ranges.

## 14. Production & deliveries
- **Log production** (＋ menu): pick the order, the stage (cutting → stitching → finishing → quality check → packing) and how many pieces passed it. The **Production board** shows each order by stage; late orders are flagged.
- **Rejects & rework**: record pieces sent back, with the worker responsible. Tick *deduct from pay* to reduce that worker's piece-rate earnings automatically.
- **Deliveries**: *New delivery* for a batch of an order (partial deliveries are fine). Open it for the **challan PDF**. When all pieces are delivered the order becomes *Delivered*.

## 15. Buying on credit (vendors)
In **Expenses**, choose payment mode **On credit**, pick the vendor and (optionally) a due date. It counts as a cost today but no cash leaves. When you pay the vendor, use **Pay a vendor** — payments clear the oldest bill first. The vendor page shows what you owe, unpaid bills and how late they are; you get an alert when a bill is due.

## 16. Payslips, vouchers & statements (PDF, WhatsApp, email)
- **Worker page** → *Payslip for the period*: PDF or **Share / WhatsApp**.
- **Payments & advances** → the eye icon opens the **payment voucher** (PV number, balance before/after) with the same buttons.
- **Customer page** → choose a period → **statement** PDF, Share / WhatsApp, or type an email and press *Email*.
- On a phone *Share* opens the share sheet with the PDF attached (pick WhatsApp). On a computer it saves the PDF and opens the WhatsApp chat with the message — attach the saved file by hand.
