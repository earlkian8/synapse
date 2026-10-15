A **kiosk** is a shared tablet or computer at one of your sites, where people **who don't have a phone** clock in. Nobody signs in to SYNAPSE on it. Each person types their **employee number** and a **PIN**, and the kiosk records their punch like any other. Kiosks are set up under **[Attendance → Kiosks](/attendance/kiosks)**.

## Adding a kiosk

1. Choose **Add kiosk**, name it after where it stands (say, _Warehouse gate_), and pick its **site**.
2. On the tablet, open the kiosk page. Its address is shown at the top of the Kiosks list. Nobody should be signed in to SYNAPSE in that browser; if someone is, the kiosk asks them to sign out first.
3. Back on the Kiosks screen, choose **Pair a device** on the kiosk's row. Type the code it shows on the tablet.

A pairing code works **once**, for **15 minutes**. Once paired, the tablet stays a kiosk until you sign it out. There's nothing to remember or keep safe on it. Use the full-screen button in the kiosk's corner so passers-by see only the kiosk.

> [!TIP]
> The site matters. A kiosk punch is placed at the kiosk's site and checked against the person's own sites, like a phone's location. If your policy flags or refuses punches away from someone's site, a kiosk at another branch is "away" for them.

## Giving people a PIN

Choose **Give PINs**. The list opens on the people who don't use the app, since a kiosk is for them. Tick everyone who will clock in at a kiosk, then **Give PINs**.

Each PIN is shown **once**. Hand each one to its holder, or choose **Print slips** for a slip per person to cut and hand out. The first time somebody uses their PIN, the kiosk asks them to **choose their own**. After that, the PIN HR gave them stops working, and nobody else knows theirs.

The list shows where each PIN stands:

- **Not chosen yet** — they haven't used the PIN you gave them;
- **Their own** — they've chosen their own;
- **Locked** — five wrong tries in a row lock a PIN for 15 minutes, even against the right one.

Somebody who forgot their PIN, or locked it, gets a fresh one from **Give a new PIN** on their row. That also unlocks it. **Withdraw PIN** stops them clocking in at any kiosk.

## Clocking in at a kiosk

1. Type your **employee number**. The digits are enough: _42_ for _EMP-00042_.
2. Type your **PIN**.
3. Your day appears. The big button is the punch you most likely came for; any other one the day allows is below it.

The kiosk shows your punch stamped on your time card, then gets ready for the next person. If you walk away part-way, it signs you out by itself and records nothing.

If the kiosk has a camera, it takes your photo as you punch. If your company's policy needs a photo with every punch, a kiosk without a working camera can't take your punch.

## Keeping kiosks safe

- A kiosk says nobody's name until their number **and** PIN match. A wrong number and a wrong PIN get the same answer.
- Too many wrong tries pause the kiosk for a few minutes.
- **Sign out the device** on a kiosk's row if a tablet is lost or replaced. **Pair a new device** moves the kiosk to another tablet, and the old one stops working at once.
- Pairing codes and PINs are only ever shown on this screen. The assistant can list kiosks and PINs, sign a device out and withdraw a PIN, but it never gives out a code or a PIN.

## Who can do what

Looking after kiosks is the permission **Set up kiosks & issue the PINs people without a phone clock in with**. Your company's attendance policies decide whether a kiosk is an accepted way to punch. See [Attendance policies](/help/company-setup/attendance-policies).
