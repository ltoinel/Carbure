"""
woob-tty.py

Runs a shell command (woob) with its standard output on a pseudo-terminal, and copies
that output unchanged to our own standard output. woob only performs a two-factor
authentication (validation in the bank app) when its output is a terminal; without one
it fails with "You have to run bank in interactive mode".

Usage: python3 tools/woob-tty.py '<command>'
The standard input stays closed (no question can be answered) and the error output
is inherited. Exits with the exit code of the command.
"""

import os
import pty
import subprocess
import sys
import tty

if len(sys.argv) != 2:
    sys.exit("usage: woob-tty.py '<command>'")

master, slave = pty.openpty()
# Raw mode: the terminal must not turn "\n" into "\r\n" (woob writes JSON lines)
tty.setraw(slave)

process = subprocess.Popen(['/bin/sh', '-c', sys.argv[1]], stdin=subprocess.DEVNULL, stdout=slave)
os.close(slave)

while True:
    try:
        data = os.read(master, 65536)
    except OSError:
        # EIO: the command closed the terminal
        break
    if not data:
        break
    # Flushed at once: the messages of woob (validation asked) are shown live
    sys.stdout.buffer.write(data)
    sys.stdout.buffer.flush()

os.close(master)
sys.exit(process.wait())
