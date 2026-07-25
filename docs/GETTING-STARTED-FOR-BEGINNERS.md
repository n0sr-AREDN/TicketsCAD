# New to servers, Docker, or the command line? Start here

You do **not** need to be a software developer to run TicketsCAD. Plenty of the
people running it are volunteers, radio operators, and first responders, not
programmers. This page is the friendly map: what TicketsCAD actually is, how to
open it once it's installed, the one thing that trips almost everyone up, and a
short list of free, beginner-friendly places to learn the basics.

## What TicketsCAD actually is

TicketsCAD is a **web application**. It runs on a computer — which can be your own
laptop or a little mini-PC — and you use it through a **web browser** (Chrome,
Firefox, Edge, Safari). There's no program icon to double-click. Instead, once
it's installed and running, you open your browser and type an **address**, the
same way you'd type `google.com`.

That computer running it is called the **server**. It can absolutely be the same
machine you're sitting at.

## The two ways to install it

- **On Windows — the easiest path — use XAMPP.** XAMPP bundles the web server
  (Apache), the database (MySQL/MariaDB), and PHP into one installer.
  A complete beginner walkthrough: https://youtu.be/i4TeaSq_7JI
  After it's installed, you open it at: **http://localhost/newui/**

- **On Linux (or anyone comfortable with containers) — use Docker.** Docker packs
  the whole app + its database into a self-contained "container." See
  [DOCKER.md](DOCKER.md). After `docker compose up -d`, you open it at:
  **http://localhost:8081**

You only need **one** of these. If you're on Docker you do **not** also need to
install Apache/PHP yourself — Docker already includes them, inside the container.

## The #1 thing that trips people up: how to open it

TicketsCAD lives at an **address** in your browser, and that address usually
includes a **port** — the number after the colon. Two rules cover almost every
"I can't find the page" question:

- **Docker installs open at http://localhost:8081** — that `:8081` is the port.
  It is **not** `:8080`, and it is **not** a folder like `http://localhost/ticketscad`.
  The whole app sits at the *root* of port 8081. (The container is *named*
  `ticketscad_newui`, but that's an internal name, not part of the web address.)
- **XAMPP installs open at http://localhost/newui/**

And one thing that confuses people: if you open plain **http://localhost** (no
port) and see a page that says *"Apache2 Ubuntu Default Page"* or *"It works!"*,
that is your computer's **own** web server saying hello — it is **not** TicketsCAD
and nothing is wrong. Just use the address with the port, above.

**Finding your login password (Docker):** the first time, a temporary admin
password is printed in the startup logs. From the folder where you ran
`docker compose`, run:

```
docker compose logs app | grep -i password
```

## Keeping it updated — learn this one, it's worth it

The best way to get every fix and every updated help document is **git**: you
"clone" the project once, and from then on a single `git pull` brings in the
latest — no re-downloading, no copying files over the top. It's genuinely worth
the 15 minutes to learn, and we made two short walkthroughs that assume zero
command-line experience:

- **Windows (Git Bash):** https://youtu.be/uZl3teJMMHM
- **Linux & macOS (Terminal):** https://youtu.be/Zczb4ypmDc8

## Learn the basics (free, beginner-friendly)

None of these are ours — they're the clearest free starting points we know of:

- **The command line / terminal (Linux):** Ubuntu's own tutorial, written for
  total beginners — https://ubuntu.com/tutorials/command-line-for-beginners
- **Docker, from scratch:** the official "Get started" —
  https://docs.docker.com/get-started/  (installing Docker on Ubuntu:
  https://docs.docker.com/engine/install/ubuntu/)
- **Git, from scratch:** the free *Pro Git* book —
  https://git-scm.com/book/en/v2  (chapters 1 and 2 are all you need to begin)
- **All the TicketsCAD how-to videos** (install, updating with git, features):
  https://www.youtube.com/playlist?list=PLeOKl8VFMO3ZmqFQ7Yxn8QZl2cqNfMLyh

## Still stuck?

Skim [TROUBLESHOOTING.md](TROUBLESHOOTING.md) — most first-week questions are
answered there. If you're still stuck, open an issue on GitHub describing what
you did and what you saw; the more specific, the faster we can help. There's no
such thing as a dumb question here.
