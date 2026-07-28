import AuthLayoutTemplate from "#/layouts/auth/auth-simple-layout.tsx";

export default function AuthLayout({
  title = "",
  description = "",
  children,
}: {
  title?: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <AuthLayoutTemplate title={title} description={description}>
      {children}
    </AuthLayoutTemplate>
  );
}
