export default async function globalSetup() {
  const database = process.env.DB_DATABASE ?? '';
  if (
    process.env.E2E_ISOLATED !== '1' ||
    process.env.APP_ENV !== 'e2e' ||
    !/^bts_e2e_[0-9]+$/.test(database)
  ) {
    throw new Error(
      'Refusing E2E: use `npm run e2e:isolated`; APP_ENV=e2e and a dedicated bts_e2e_<pid> database are mandatory.'
    );
  }
}
