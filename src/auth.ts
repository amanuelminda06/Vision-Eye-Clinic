import NextAuth from 'next-auth';
import Credentials from 'next-auth/providers/credentials';
import { env } from '@/env';
import { verifyCredentials } from '@/modules/auth';

export const loginPath = `/login/${env.LOGIN_SECRET_PATH}`;

/** 12-hour session (spec). */
const SESSION_MAX_AGE = 12 * 60 * 60;

export const { handlers, auth, signIn, signOut } = NextAuth({
  session: { strategy: 'jwt', maxAge: SESSION_MAX_AGE },
  trustHost: true,
  pages: { signIn: loginPath },
  cookies: {
    sessionToken: {
      options: {
        httpOnly: true,
        sameSite: 'lax',
        secure: process.env.NODE_ENV === 'production',
        path: '/',
        maxAge: SESSION_MAX_AGE,
      },
    },
  },
  providers: [
    Credentials({
      credentials: {
        email: { label: 'Email', type: 'email' },
        password: { label: 'Password', type: 'password' },
      },
      authorize: (credentials) => verifyCredentials(credentials),
    }),
  ],
  callbacks: {
    jwt({ token, user }) {
      if (user) {
        token.role = user.role;
        token.mustChangePassword = user.mustChangePassword;
      }
      return token;
    },
    session({ session, token }) {
      session.user.id = token.sub ?? '';
      if (token.role) session.user.role = token.role;
      if (typeof token.mustChangePassword === 'boolean') {
        session.user.mustChangePassword = token.mustChangePassword;
      }
      return session;
    },
  },
});
