---
title:	"Dependency Injection in ASP.NET Web Forms"
date:	2010-10-03
author: Steven van Deursen
tags:   [.NET General, C#, Dependency Injection]
draft:	false
---

### This article describes how to create and configure a custom PageHandlerFactory class that enables automatic constructor injection for System.Web.UI.Page classes. This keeps your application design clean and allows you to keep the application’s dependency to the IoC library to a minimum.

#### **IMPORTANT**: Since the introduction of Web Forms v4.7.2, there is now better support for DI. That makes this article out-dated.

When working with IoC frameworks, one should always try to minimize the amount of application code that takes a dependency on that framework. In an ideal world, there would only be a single place in the application were the container is queried for dependencies. ASP.NET Web Forms however, was never designed with these concepts in mind. It therefore is tempting to directly request for dependencies in the Page classes instead of looking for a workaround. This is what a Page could look like without such a workaround:

{{< highlight csharp >}}
public partial class _Default : System.Web.UI.Page
{
    private IUserService userService;

    public _Default()
    {
        this.userService = ServiceLocator.Current.GetInstance<IUserService>();
    }
} 
{{< / highlight >}}

While this doesn’t look that bad, it creates a dependency on an particular implementation and even when your calling an abstraction (as I do with the Common Service Locator in the example) you might want to prevent this, because you’ve still got a dependency and a bit of plumbing in each and every page.

The way to intercept the creation of Page types in ASP.NET Web Forms, is by replacing the default PageHandlerFactory implementation. While some think that automatic constructor injection is not possible with Web Forms, I will show you otherwise.

The code below shows my CommonServiceLocatorPageHandlerFactory. This is a PageHandlerFactory that uses automatic constructor injection to create new Page types by using the Common Service Locator (CSL) interface. I deliberately use the CSL for this, because my Simple Service Locator library depends on that interface. If you're not using the CSL, changing the code to work with your IoC library is can be done by changing a single line, as you will see below.

When using this custom PageHandlerFactory the previously shown Page class can be changed to the following:

{{< highlight csharp >}}
public partial class _Default : System.Web.UI.Page
{
    private IUserService userService;

    protected _Default()
    {
    }

    public _Default(IUserService userService)
    {
        this.userService = userService;
    }
}
{{< / highlight >}}

Please note that the page must contain the default constructor. The code compilation model that ASP.NET uses behind the covers, creates a new type based on the defined _Default type. ASP.NET does this to allow the creation of the control hierarchy as it is defined in the markup. Because of this inheriting strategy, every Page class in your application must have a default constructor, although it doesn’t have to be public.

Registration of the CommonServiceLocatorPageHandlerFactory can be done in the web.config in the following way:

{{< highlight xml >}}
<?xml version="1.0"?>
<configuration>
  <system.web>
    <httpHandlers>
      <add verb="*" path="*.aspx"
        type="CSL.CommonServiceLocatorPageHandlerFactory, CSL"/>
    </httpHandlers>
  </system.web>
  <system.webServer>
    <handlers>
      <add name="CSLPageHandler" verb="*" path="*.aspx"
        type="CSL.CommonServiceLocatorPageHandlerFactory, CSL"/>
    </handlers>
  </system.webServer>
</configuration>
{{< / highlight >}}

Here is the code for the CommonServiceLocatorPageHandlerFactory:

{{< highlight csharp >}}
public class SimpleInjectorPageHandlerFactory 
    : PageHandlerFactory
{
    private static object GetInstance(Type type)
    {
        // Change this line if you're not using the CSL,
        // but a DI framework directly.
        return Microsoft.Practices.ServiceLocation
            .ServiceLocator.Current.GetInstance(type);
    }

    public override IHttpHandler GetHandler(HttpContext context,
        string requestType, string virtualPath, string path)
    {
        var handler = base.GetHandler(context, requestType, 
            virtualPath, path);

        if (handler != null)
        {
            InitializeInstance(handler);
            HookChildControlInitialization(handler);
        }

        return handler;
    }

    private void HookChildControlInitialization(object handler)
    {
        Page page = handler as Page;

        if (page != null)
        {
            // Child controls are not created at this point.
            // They will be when PreInit fires.
            page.PreInit += (s, e) =>
            {
                InitializeChildControls(page);
            };
        }
    }

    private static void InitializeChildControls(Control contrl)
    {
        var childControls = GetChildControls(contrl);

        foreach (var childControl in childControls)
        {
            InitializeInstance(childControl);
            InitializeChildControls(childControl);
        }
    }

    private static Control[] GetChildControls(Control ctrl)
    {
        var flags =
            BindingFlags.Instance | BindingFlags.NonPublic;

        return (
            from field in ctrl.GetType().GetFields(flags)
            let type = field.FieldType
            where typeof(UserControl).IsAssignableFrom(type)
            let userControl = field.GetValue(ctrl) as Control
            where userControl != null
            select userControl).ToArray();
    }

    private static void InitializeInstance(object instance)
    {
        Type pageType = instance.GetType().BaseType;

        var ctor = GetInjectableConstructor(pageType);

        if (ctor != null)
        {
            try
            {
                var args = GetMethodArguments(ctor);

                ctor.Invoke(instance, args);
            }
            catch (Exception ex)
            {
                var msg = string.Format("The type {0} " +
                    "could not be initialized. {1}", pageType,
                    ex.Message);

                throw new InvalidOperationException(msg, ex);
            }
        }
    }

    private static ConstructorInfo GetInjectableConstructor(
        Type type)
    {
        var overloadedPublicConstructors = (
            from ctor in type.GetConstructors()
            where ctor.GetParameters().Length > 0
            select ctor).ToArray();

        if (overloadedPublicConstructors.Length == 0)
        {
            return null;
        }

        if (overloadedPublicConstructors.Length == 1)
        {
            return overloadedPublicConstructors[0];
        }

        throw new ActivationException(string.Format(
            "The type {0} has multiple public overloaded " +
            "constructors and can't be initialized.", type));
    }

    private static object[] GetMethodArguments(MethodBase method)
    {
        return (
            from parameter in method.GetParameters()
            let parameterType = parameter.ParameterType
            select GetInstance(parameterType)).ToArray();
    }
}
{{< / highlight >}}

This implementation does one sneaky thing to achieve it’s goal. It is nearly impossible to instantiate the type our self, because that would mean that we need to rewrite the complete compilation engine of ASP.NET. Instead we delegate the creation to the PageHandlerFactory base class. After the creation of this type (created using the default constructor) we search for an overloaded constructor on its base type (remember that ASP.NET creates a sub type), find out what arguments this constructor has, and load those dependencies by calling the Common Service Locator. After that we invoke that overloaded constructor. I repeat: *we call an overloaded constructor on an already initialized class*.

This is very sneaky, but works like hell. Besides initializing the Page class itself, it will initializes all UserControls recursively.

A few side notes: Keep in mind that this will fail in partially trusted environments. When doing this in partial trust, there is no good feasible workaround. In partial trust there is not much else we can do than seeing the Page as a **Composition Root** and calling the container from within the default constructor. Second note: This will only work for .aspx pages. For intercepting the creation of .ashx HTTP Handlers we will need to create a custom IHttpHandlerFactory, which is new since ASP.NET 2.0.

Happy injecting!

## Comments

Comments haven't been migrated yet...