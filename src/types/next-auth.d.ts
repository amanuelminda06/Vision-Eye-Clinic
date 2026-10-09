import type { Role } from '@prisma/client';
import type { DefaultSession } from 'next-auth';

declare module 'next-auth' {
  interface Session {
    user: {
      id: string;
      role: Role;
      mustChangePassword: boolean;
    } & DefaultSession['user'];
  }

  interface User {
    role: Role;
    mustChangePassword: boolean;
  }
}

declare module '@auth/core/jwt' {
  interface JWT {
    role?: Role;
    mustChangePassword?: boolean;
  }
}
