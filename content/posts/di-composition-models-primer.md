---
title:			"DI Composition Models: A Primer"
date:			2019-07-02
author: 		Steven van Deursen
reviewers:		Peter Parker and Ric Slappendel
proofreaders:	Katie Tennant
tags:			[.NET General, Architecture, C#, Dependency Injection]
gitHubIssueId:	1
draft:			false
aliases:
    - /p/compositionmodels
---

### To be able to achieve anything useful, your application code makes use of runtime data that comes in many shapes and forms. Providing access to that data can be accomplished in many ways. The way you provide object graphs with runtime data can affect the way you compose them using Dependency Injection. There are two competing models to choose from. This article introduces these two models: the Closure Composition Model and the Ambient Composition Model. It is the first of a five-part series on Dependency Injection composition models.

Posts in this series:

* [DI Composition Models: A Primer](/steven/p/compositionmodels) (this article)
* [The Closure Composition Model](/steven/p/ccm)
* [The Ambient Composition Model](/steven/p/acm)
* [DI Composition Models: A Comparison](/steven/p/cmcompare)
* [In Praise of the Singleton Object Graph](/steven/p/singleton)

Most of your application code uses runtime data in one form or another. Runtime data flows through the system in many forms. Your application will get its data from and send its data to browsers, databases, queues, services, the filesystem, and many other sources.

{{< figure src="/steven/images/compositionmodels/systemdata.svg" width="100%" alt="Data flowing through the system" >}}

The data flowing through your application can be categorized in many ways, but for the remainder of this article, I’ll divide it into two groups, as this serves us in the discussion that follows:

* ***Data passing through the public API***—Data that is received from or sent to external actors, such as data posted by a web browser or sent to a message queue, defines a system’s public API. Such information becomes the raw data input or output of a use case. This data might be transformed and reshaped when it is sent from layer to layer, but each layer still passes it through the public API of the next layer. The figure following the next bullet depicts this.
* ***Contextual or internally oriented data***—This is data that isn’t passed through the system’s public API, but is instead an implementation detail of individual components in the system. Often this data is more contextual in nature, while still influencing business decisions. For instance, an application might decide not to execute a certain operation when the user isn’t in the correct role. Users can’t influence their role directly by clicking “Cancel Order.” Instead, their identity and role are already available as contextual information.

{{< figure src="/steven/images/compositionmodels/datapassingpublicapi.svg" width="100%" alt="Each layer passes runtime data through the public API of the next layer" >}}

Note that the two groups are not strictly separated. Consider, for instance, how the user’s role can flow through the system with different use cases:

* The application administrator might change a user’s role by means of the administration screens. This means that the administrator supplies data to the application through the application’s *public API*.
* Later, when the user starts using the application, that same role data has become *contextual information* that allows the system to do the proper security checks.
* The application will likely show the user’s role on one of the user’s screens—in that case, the role has again become *publically exposed data*.

This means that data can hop from one group to the next and back, depending on the use case in which it participates. To simplify things, however, I will ignore this possible group-hopping behavior for the remainder of this article—it’s not that relevant.

In the next sections, I’ll discuss these two groups of data in more detail, starting with the first group. This will provide the necessary context for the remainder of the article, where I'll describe their significance in the creation of your application components. Finally, building on that, I’ll introduce the two composition models that you can use to create an object graph.

## Data passing through the public API

Let’s focus on the first group for a moment: consider web-request data, posted by a browser. If you’re building an ASP.NET (or ASP.NET Core) MVC application, a browser’s HTTP request is transformed by the framework into a view model object and passed to your MVC controller classes. The following sequence diagram visualizes this process:

{{< figure src="/steven/images/compositionmodels/publicdatasequencediagram.svg" width="100%" alt="Sequence diagram visualizing how a browser's HTTP request is transformed to public data" >}}

In this sequence diagram, the user’s request is handled by the framework. The framework then transforms and forwards the call to a controller’s action method—in this case, the `AddItem` method on `ShoppingBasketController`. When the controller finishes, it returns an action result that's used to render HTML. The HTML is then sent back to the user.

ASP.NET will provide the `AddItem` action method with the runtime data coming from the user. The following code listing shows `ShoppingBasketController` with its `AddItem` method:

{{< highlightEx csharp >}}
public class ShoppingBasketController : Controller
{
    [HttpPost]
    public IActionResult AddItem( //{{annotate}}The application’s public API{{/annotate}}
        AddShoppingBasketItem viewModel) //{{annotate}}Runtime data{{/annotate}}
    {
        ...
    }
}
{{< / highlightEx >}}

The `AddItem`’s `AddShoppingBasketItem` argument captures the request data. It is runtime data, unique to the request, its data posted by the client, and supplied by the ASP.NET framework to `ShoppingBasketController`. The `AddShoppingBasketItem` runtime data is passed from the caller (the framework) to the callee (`ShoppingBasketController`) through the class’s public API (the `AddItem` method). This works great for request/response-related runtime data—such as `AddShoppingBasketItem`—but might not work well in other cases, which brings me to the second group of runtime data.

## Contextual or internally oriented data

The `AddShoppingBasketItem` view model specifies the runtime data required by the `AddItem` API. But not all runtime data should be supplied to a class through its public API. Some data is an implementation detail—leaking its existence through the public API could complicate things for the clients, cause maintainability issues, or even raise security concerns. In many cases, this implementation-specific runtime data is more contextual in nature.

Take, for instance, the identity of the current user that is issuing the request. Part of the application needs to be aware of the user’s identity. Although the HTTP operation sends the identity, that information is not supplied to the controller’s public API. Making the identity part of `AddShoppingBasketItem`, for instance, could cause several problems—most likely, a security risk. It is not up to the user to supply an unverified identity through this request's POST information. The user’s identity has long been established, and a security token is typically sent using a different "channel" (a cookie). The user identity can, in the context of adding an item to a basket, be regarded as an implementation detail.

Another example of implementation-detail runtime data is a Unit of Work, such as Entity Framework’s `DbContext`. From the perspective of the application’s public API, it is an implementation detail. `AddShoppingBasketItem`, for example, should *not* have to change if you decide to change the application’s persistence layer.

`DbContext` is a glorified state bag with cached and mutated entities, ready to be persisted at some point. Each request gets its own local set of entities, cached for the duration of the web request, and reusing it across requests is a bad idea. You wouldn’t let the browser provide the controller with a `DbContext`—that would be a scary thought. But equally so, you wouldn’t pass on a `DbContext` through the public API of the individual layers.

## The significance of contextual data in the context of Object Composition

When it comes to composing object graphs using DI, the difference between `AddShoppingBasketItem` and `DbContext` becomes significant. While both constitute runtime data, you design your classes differently around them. As explained, the application’s abstractions and external-facing APIs expose runtime data objects such as `AddShoppingBasketItem`. Runtime data objects such as `DbContext`, however, are instead hidden behind these same abstractions, making them mere implementation details of their direct consumers. This means that whereas `AddShoppingBasketItem` is passed to the public methods of an already composed object graph, `DbContext` is supplied using a different mechanism—one option being [Constructor Injection](https://mng.bz/oN9j).

The following simplified object graph shows this. The application’s `ShoppingBasketDbContext` is created and supplied to the controller’s constructor:

{{< highlightEx csharp >}}
var controller =
    new ShoppingBasketController(
        {{**}}new ShoppingBasketDbContext(){{/**}}); //{{annotate}}Constructor injection{{/annotate}}
{{< / highlightEx >}}

Later, when a web request comes in, the deserialized view model is passed along to the controller’s `AddItem` method. At that point, however, the controller’s object graph has long since been created.

This way of supplying `ShoppingBasketDbContext` to the object graph during construction is one model you can use to compose your object graphs, called the *Closure Composition Model*.

{{% callout DEFINITION %}}
The **Closure Composition Model** composes object graphs that capture runtime data in variables of the graph’s components.
{{% /callout %}}

An alternative to letting your application components consume these contextual runtime data objects is the *Ambient Composition Model*. With that model, contextual runtime data is no longer captured in variables inside the object graph, but instead is managed by the startup path of the application—in DI terminology the [Composition Root](https://mng.bz/K1qZ). Application components requiring such data request it through method calls on provided abstractions.

{{% callout DEFINITION %}}
The **Ambient Composition Model** composes object graphs that do not store runtime data inside captured variables. Instead, runtime data is kept outside the graph and stored as ambient data. This ambient data is managed by the Composition Root and is provided to application components on request, long after those components have been constructed.
{{% /callout %}}

This completes this primer on Object Composition models. At this point, much remains to be explained, such has how application components are designed in each model, what exactly *ambient data* is, and what the pros and cons are of both models. I will go into this in more detail in the following articles, starting with a description of the [Closure Composition Model](/steven/p/ccm/), and continuing with a description of the [Ambient Composition Model](/steven/p/acm/).

Stay tuned.

## Summary

* Runtime data can be roughly divided into two groups:
  * *Runtime data that is exposed by the application’s abstractions and public API*. This data is passed along public methods on already created object graphs.
  * *Runtime data that is more internal or contextual in nature*. Classes require this data, while hiding that information from their public API.
* While the first group does not influence the way object graphs are composed, the way you choose to provide the second group to your application components influences your choice of which Object Composition Model to use. You can provide internal and contextual runtime data using either of these:
  * *The* ***Closure Composition Model***—Lets you compose object graphs that capture runtime data in variables of a graph’s components
  * *The* ***Ambient Composition Model***—Lets you store runtime data outside the object graph as ambient data, which is managed by the Composition Root

## Comments