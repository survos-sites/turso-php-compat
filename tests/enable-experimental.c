/* Diagnostic only: opt into the PR's existing experimental API before PHP starts.
 * No PHP, Doctrine, Folio or Turso source is patched.
 * This enables generated columns, vacuum, WITHOUT ROWID and ATTACH globally.
 */
extern void turso_enable_experimental(void);
__attribute__((constructor)) static void enable_turso_experimental(void) {
    turso_enable_experimental();
}
