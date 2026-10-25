# Members, ID cards, custom fields and import/export

Permission: **members and groups** (custom fields and membership settings:
*super admin*).

## How someone becomes a member

1. They fill in the joining form at `/join` (see the
   [member guide](../member-guide.md#joining)).
2. Gather emails them a verification link. Until they click it they are
   **awaiting verification**: they can log in and edit their profile, but
   have no member number or card yet.
3. When they verify, they become **active**: they get their **member
   number**, a **card code** for the QR check, a **valid until** date, and a
   welcome email with their number.
4. Accounts that are not verified within **14 days** are deleted
   automatically, with their photo.

The joining form has spam protection (a hidden trap field, a time check and
a limit per network address), so you should rarely see fake sign-ups.

## The members list

**Admin → Members**.

![Members](../images/admin-members.webp)

- **Search** by name, email, member number or phone.
- **Filters**: **Status** (**Active**, **Awaiting verification**,
  **Suspended**, **Card expired**, **Deletion requested**), **Membership
  type**, **Region**, and any **Custom field** with a value.
- The heading shows how many are active, awaiting verification, and (if any)
  have asked for deletion. Click that link to see them.
- **Export CSV** / **Import CSV**: see below.
- Tick members and click **Print selected cards**, or **Print all filtered
  cards**: see [Printing cards](#printing-cards).

## A member's page

Click a member.

![A member's page](../images/admin-member-edit.webp)

**Profile**: email, name, birthday (the year is optional and never shown
publicly), phone, gender, address, town, region, country, nationality, and
the **Custom fields** (including ones only admins can see).

**Membership**:

- **Membership type**: from your list in Membership settings.
- **Fee status**: **free**, **due**, **paid** or **waived**, for your own
  records. Gather takes no payments.
- **Card valid until**: the expiry date printed on the card. Leave it empty
  for a card that never expires. To renew someone, set a new date.

**Photo**: replace the member's photo (cropped to a square).

Click **Save member** after changes.

**Account** (right-hand side):

- **Mark email as verified**: for someone who cannot receive the email (they
  then get their number straight away).
- **Suspend** / **Reactivate**: a suspended member cannot log in.
- **Email a password link**: sends a link to set a new password.

**ID card**: **View / print card** (top right), the **card code** and its
**check page**, and **Regenerate card code**. Do that if a card was lost:
the old card's QR code stops working.

**Notes**: private notes about the member, only visible to staff (for
example "Paid in cash at the AGM").

**Remove member**: type `DELETE` and click **Remove member**. This deletes the
account and photo. Event registrations are kept without the name, comments
show as "former member", and the member number is retired (never given to
anyone else).

### Deletion requests

Members can ask for their account to be deleted (My account → Your data).
Super admins get an email. The member's page shows a notice with the date,
and the list's status filter **Deletion requested** finds them. Check
there is no reason to keep the data, then use **Remove member**. UK GDPR
expects you to act within one month.

## ID cards

Every active member has a digital card with their photo, name, number,
membership type, valid-until date, up to two custom fields, your logo,
your contact details, a signature, and a **QR code**.

Scanning the QR code opens a check page on your site showing **Valid**,
**Expired** or **Not valid**, with the member's name and photo only. Show
door volunteers how it works.

Members download or print their own card from their account. See the
[member guide](../member-guide.md#your-member-card).

### Printing cards

**Print selected cards** (or **Print all filtered cards**) opens a print
page with A4 sheets of eight cards: first a sheet of **fronts**, then a sheet
of **backs** with the columns swapped, so double-sided printing lines up.
Use your browser's **Print** (or **Save as PDF**), at 100 % size, on card
stock. Each card is the size of a standard ID card (85.6 × 54 mm).

## Membership settings

*Super admins only.*

**Admin → Membership settings**:

![Membership settings](../images/admin-membership-settings.webp)

**Member numbers**

- **Member prefix** (for example `HCA`) and **Group prefix** (for example
  `GRP`).
- **Number format**: built from **{PREFIX}**, **{YY}** or **{YYYY}** (the year
  of verification) and **{0000}** (the running number, 2–8 digits). The
  example underneath updates as you type. With a year in the format, the
  count restarts each year (`HCA-26-0001`, then `HCA-27-0001`).
- Numbers are given out when a member verifies their email and are
  **never reused**, even after a member is removed.

**Sign-up form**

- **Membership valid for (months)**: sets "valid until" on new cards.
- **Free for the first N members**: shows a "free membership for the first N
  members" banner on the joining form and marks those members' fee status
  as free. 0 switches it off.
- **Terms and privacy page**: the link in the consent box (for example
  `/privacy-policy`).
- **Tick the "news and event updates" box by default**: leave this **off**.
  Under UK GDPR and PECR a pre-ticked box is not valid consent for marketing
  emails. Members can opt in later from their dashboard or email
  preferences.
- **Regions**, **Gender options** (gender is always optional) and
  **Membership types**, one per line. The first membership type is given to
  new members.

**ID card artwork**

- **Signatory title**: printed under the signature (for example
  "Secretary").
- **Card background**: a faint pattern or photo behind the front of the card.
- **Signature image**: a PNG with a transparent background works best.

Click **Save settings**.

## Custom fields

*Super admins only.*

Extra questions on the joining form and member profiles, for example
"District of origin", "Year of graduation" or "Volunteer skills".

**Admin → Custom fields → New field**:

- **Label**: the question.
- **Type**: **Text**, **Choice from a list** (type the **Choices**, one per
  line), **Date** or **Tick box**.
- **Asked of**: **Members** or **Group accounts**.
- **Required**.
- **Visible to members**: asked at sign-up and editable on their profile.
  Untick for admin-only fields (for example "Committee notes" or
  "Gift Aid declared").
- **Show on the member card**: up to two fields fit.
- **Order**.

**Delete field** removes the field *and every answer* members gave.

![Custom fields](../images/admin-custom-fields.webp)

![Editing a custom field](../images/admin-custom-field-edit.webp)

Custom fields can be used to filter the members list and to choose a
campaign audience ("Members with a custom field value").

## Exporting members

**Members → Export CSV** downloads the list (following the current
filters) as a spreadsheet: member number, email, names, phone, birthday,
gender, address, nationality, country, region, city, membership type,
status, card valid until, joined date, and one column per custom field
(`cf_…`).

The file contains personal data: store it safely and delete it when you
no longer need it. (Values that could be read as spreadsheet formulas are
made safe.)

## Importing members from a spreadsheet

**Members → Import CSV**:

![Importing members](../images/admin-members-import.webp)

1. Prepare a CSV file (UTF-8, comma separated, first row = column names).
   The easiest start is to **export** the current list and use it as a
   template.
   - Required columns: `email`, `first_name`, `last_name`.
   - Optional: `member_number`, `phone`, `birth_day`, `birth_month`,
     `birth_year`, `gender`, `address`, `nationality`, `country`, `region`,
     `city`, `membership_type`, `card_valid_until`, and `cf_…` for custom
     fields. Dates as `YYYY-MM-DD`.
2. Choose the file. Options:
   - **Update members whose email already exists** (otherwise those rows are
     skipped).
   - **Email new members their number and how to set a password**.
3. **Import**.

Imported members are **active** straight away with a member number (the one
in `member_number` if that number was never issued before, otherwise a new
one). Up to 5,000 rows at a time. Afterwards you see how many were created,
updated and skipped, with the reason for each skipped row (for example an
email domain that cannot receive mail).

Imported members have no password yet. They set one with the welcome email's
link, or with **Forgotten your password?** on the login page.
