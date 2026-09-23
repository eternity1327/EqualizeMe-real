# Corrections for the DRAFT paper

Every claim below was checked against the code as it stands. Replace the
quoted text; the reasoning is there so you can defend the wording rather
than just paste it.

---

## 1. Python — the significant one

**The problem.** Three passages say Python performs the system's
computational and AI-assisted work at runtime, and one says it runs as a
layer separate from PHP. That described the earlier architecture, in which
a Flask service did the profiling and PHP proxied to it. That service was
removed. Nothing Python does now happens while a user is on the site.

**Why the code will not be changed back.** InfinityFree does not run
Python at all — only PHP. Restoring the old architecture would mean
abandoning the deployment the system is actually hosted on. It would also
reintroduce the flaw the port fixed: the Flask service trusted whatever
user id it was handed, so a PHP proxy existed purely to overwrite it. The
current endpoints read the session directly, which makes that class of bug
unreachable rather than merely guarded against.

**Why Python still belongs in the paper.** It does the measurement
pipeline and the calibration, which is real computational work — it is
simply offline. Specifically:

- `fetch_measurements.py` retrieves raw frequency-response measurements
- `measurement_parser.py` parses them and computes each band relative to a
  500–2000 Hz midrange reference: bass 20–250 Hz, presence 2000–6000 Hz,
  treble 6000–16000 Hz
- `catalog_parser.py` extracts catalogue metadata such as price
- `import_to_db.py` averages left and right channels and writes the curves
  into MySQL
- `calibrate_interpreter.py` derives the interpreter's band thresholds
  from the distribution of the catalogue itself, using percentile cuts,
  rather than from hand-picked numbers
- `interpreter.py` holds those derived thresholds and produces each IEM's
  sound-signature description

That last pair is the strongest point available: the constants the live
PHP uses were **produced** by Python from the measurement data. The paper
can say that truthfully.

### Replace, in Objectives

> Create the system using HTML, CSS, and JavaScript for the user
> interface; PHP and Python for backend processing and AI-assisted
> auditory profiling; MySQL for database management; ...

with

> Create the system using HTML, CSS, and JavaScript for the user
> interface; PHP for server-side request processing and the AI-assisted
> auditory profiling computations; the Web Audio API for browser-based
> audio processing and equalization; Python for the offline measurement
> pipeline that builds and calibrates the IEM catalogue; MySQL for
> database management; Visual Studio Code as the IDE; and Figma or Canva
> for UI design.

### Replace, in the Python subsection of Chapter 2

> EqualizeME uses Python for computational and AI-assisted functions
> associated with processing assessment observations and supporting
> adaptive preference profiling. Its separation from the primary PHP Web
> layer allows computational ...

with

> EqualizeME uses Python for its offline data-processing layer rather than
> for handling user requests. Python retrieves published frequency-response
> measurements, parses them, computes band-level values relative to a
> midrange reference, averages measurement channels, and writes the
> resulting curves into the system database. Python is also used to
> calibrate the interpretation thresholds: rather than assigning
> descriptive boundaries by hand, the calibration script derives them from
> the distribution of the assembled catalogue, and the resulting constants
> are what the deployed PHP implementation applies. This separation places
> the computationally heavy and infrequent work outside the request path,
> so a user's page load never waits on it, and allows the system to be
> deployed on PHP-only shared hosting.

### Replace, in the technology summary

> ... PHP provides server-side processing; Python supports computational
> and AI-assisted functions; Web Audio API supports browser-based audio
> processing ...

with

> ... PHP provides server-side processing together with the auditory
> profiling and recommendation computations; the Web Audio API provides
> real-time equalization and audio playback in the browser; Python
> provides the offline measurement pipeline and threshold calibration ...

### Add to Scope and Delimitations

> The system's runtime processing is implemented entirely in PHP and
> JavaScript so that it can be deployed on PHP-only shared hosting.
> Python is used for offline preparation of the IEM measurement catalogue
> and for calibrating interpretation thresholds, and is not executed
> during user interaction with the system.

---

## 2. Use Case — the Admin/User split is the wrong way round

**The problem.** The use case paragraph reads:

> The User could also import IEM profiles and add music for use within the
> system's personalization features. Meanwhile, the Admin could access
> administrative functions for managing the IEM profiles and maintaining
> the system configuration.

This contradicts your own glossary, which defines the Administrator as
responsible for "the management of user accounts, system data, auditory
assessment content, and the IEM database". Importing IEM profiles and
adding music are exactly that. It also contradicts the implementation:
`api/admin/songs.php` and `api/admin/upload-song.php` both call
`require_admin()` and answer 404 to an ordinary account.

### Replace with

> Figure 2 illustrated the Use Case Diagram of the EqualizeME system. It
> showed the main interactions between the two actors in the system: Admin
> and User. Each actor was connected to the system functionalities
> corresponding to their assigned role. The User could register an account,
> verify their email address, log in, verify a two-factor authentication
> code when required, reset a forgotten password, take the listening test,
> view personalized recommendations, review their assessment history,
> manage their profile and settings, and log out of the system. The Admin
> could additionally manage the auditory assessment content by uploading
> and assigning the audio tracks used in the listening test, and maintain
> the IEM measurement catalogue and system configuration. The diagram
> provided an overview of the system's functional requirements and the
> interactions available to each user role.

The Level 0 DFD paragraph is already correct on this point — it puts
system configuration and IEM database management on the Admin side — but
it should also mention assessment audio, so that the two figures agree.

---

## 3. Citations

- **`Olive, at al. (2013)`** — "at" should be "et". One occurrence.

- **Ambiguous 2017.** The reference list contains Olive et al. **2017a**
  and **2017b**. The in-text citation is a bare "2017", which under APA
  must carry the letter.

- **Wrong year, same sentence.** That citation describes "31 around-ear
  and on-ear headphone models and 130 listeners". That is the **2018**
  paper. The 2017a/b papers are about in-ear headphones. Either change the
  citation to 2018 or change the description to match the in-ear study —
  but as written the year and the content disagree.

- **phpMyAdmin year.** In-text says "(phpMyAdmin, 2026)"; the reference
  list says "phpMyAdmin. (n.d.)". Pick one.

---

## 4. MySQL and MariaDB

The paper says MySQL ten times and MariaDB three times. In practice both
XAMPP and InfinityFree ship **MariaDB**, which is a fork of MySQL and
speaks the same SQL — everything written about MySQL remains true, but a
panel that checks phpMyAdmin will see MariaDB and ask.

One sentence handles it, in the MySQL subsection:

> The system uses MySQL-compatible relational database management. The
> development and deployment environments both provide MariaDB, a fork of
> MySQL that implements the same SQL dialect and client protocol, so the
> database layer is referred to as MySQL throughout this study.

---

## 5. Fixed in the code, not the paper

The interface labelled the presence band "~2-5 kHz" while
`measurement_parser.py` computes it over 2000–6000 Hz. The label now reads
"~2-6 kHz". If any figure or table in the paper gives the band ranges,
check it says 2–6 kHz.
