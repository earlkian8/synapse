A QR station is a screen at one of your sites — a TV by the entrance, a monitor at the front desk, a tablet on a stand — that shows a code people scan with their phone to clock in. The code changes every few seconds, so a photo of it is useless almost at once. Open **Attendance** → **QR stations**.

## With the app, or with any phone

Each station is set to one of two ways of scanning:

- **With the SYNAPSE app** (the strongest). People scan from **Clock** in the app. The app knows whose phone it is, and Android tells it when a location is faked.
- **With any phone's camera.** No app needed. The phone's camera opens a page in its browser, where people type their **employee number** and the **PIN they use at kiosks**. Issue PINs under **Attendance** → **Kiosks** → **PINs** first; the station's form says how many people have one. The first time, people choose a PIN of their own on that page.

A browser can't tell when a location is faked, and a PIN is easier to pass on than a phone. Where that matters, keep the station to the app, or turn on **Only on the office Wi-Fi**.

## What every scan is checked for

A scan clocks somebody in only when all of these hold:

- **The code is the one on the screen now.** A code is good for its own turn and the next one (30 seconds at the default of 15), and only while a screen is showing the station. Close the screen and every code stops within three minutes.
- **The phone is at the site.** Its location has to be inside the station's site, as drawn under **Company Setup** → **Locations**, and precise to within 100 m.
- **The location is real.** A phone that reports a simulated location (an app that fakes GPS) is turned away. Only the app can tell.
- **One phone, one person.** A phone (or a phone's browser) that clocked somebody in can't clock in anybody else for 12 hours, so nobody clocks in a friend from their own phone.
- **On the office Wi-Fi**, when the station asks for it. The phone must reach SYNAPSE through the same internet connection as the screen, so someone at home is turned away even with a faked location. Phones on mobile data are turned away too, so tell people to join the Wi-Fi.

Their attendance policy still decides the rest: whether a selfie is needed, the day's order, and whether QR punches are taken at all.

> [!TIP]
> To make everyone clock in at the screen, open their **Attendance Policy** and, under **How people punch**, leave **QR station** on and turn **Mobile app** and **Web** off. The app then shows **Scan to Time In** instead of the plain button.

## Adding a station

1. Choose **Add station**.
2. Name it for where the screen is, such as *Front desk*.
3. Choose its **Site**. A scan is accepted only from inside it.
4. Choose how often the code changes. **15 seconds** suits most screens.
5. Choose **How people scan it**: with the SYNAPSE app, or with any phone's camera.
6. Turn on **Only on the office Wi-Fi** if scans should count only from the office network.
7. Choose **Add station**.

## Showing the code

Choose **Show code** beside the station. It opens full screen in a new tab: the code, the time, how to scan, and the last few people it clocked in. Put that tab on the screen at the site and use the full-screen button.

Every two minutes the screen asks SYNAPSE for the next few minutes of codes, and shows each one only in its own turn. It holds nothing that could make a code. While its tab is hidden it asks for nothing, so a screen left on all day costs the server about thirty small requests an hour. If it can't reach SYNAPSE and its codes run out, it says **Reconnecting** and shows no code, so nobody scans one that has run out.

> [!IMPORTANT]
> The screen is signed in as whoever opened it. To put a station on a shared screen, sign it in with an account whose role holds only **Show a QR station's code on a screen**, so the screen can do nothing else.

## Reading the scans

**Latest scans** lists every scan, newest first, with who scanned, what it did and where the phone was. A scan made without the app says *in a browser*; a wrong PIN shows as *Not identified*. Choose **Refused** to see only the scans that were turned away. A refusal in red looks like somebody getting round the station — a code from somewhere else, a faked location, one phone for two people — and is also in the activity log.

## When something's wrong

- **Reset codes** ends every code the station has shown, at once. Use it if the screen was left somewhere it shouldn't have been.
- **Switch off** stops the station taking scans until you switch it on again.
- **Remove** ends it for good. Punches scanned there keep saying where they were made.
